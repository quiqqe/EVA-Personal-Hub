<?php

declare(strict_types=1);

namespace App\Money;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Exchange rates as RUB per one unit.
 * Today: Central Bank of Russia for fiat, CoinGecko for crypto.
 * Past days: daily snapshots (fiat + crypto, since March 2024), the Central Bank archive for older fiat;
 * stored in money_rate_day once fetched. A currency missing on a day falls back to today's rate.
 */
final class Rates
{
    private const SNAPSHOT = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@%s/v1/currencies/rub.min.json';
    private const SNAPSHOT_MIRROR = 'https://%s.currency-api.pages.dev/v1/currencies/rub.min.json';
    private const CBR_ARCHIVE = 'https://www.cbr-xml-daily.ru/archive/%s/daily_json.js';

    /** @var array<string, array<string, float>> 'Y-m-d' => rates, for this request */
    private array $days = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly Connection $db,
    ) {
    }

    /** @return array<string, float> today's rates */
    public function all(): array
    {
        return $this->fiat() + $this->crypto();
    }

    /** Converts at the rate of $on (today when null or not in the past). */
    public function convert(int $minor, string $from, string $to, ?\DateTimeImmutable $on = null): ?int
    {
        if ($from === $to) {
            return $minor;
        }
        $rate = $this->rate($from, $to, $on);

        return $rate === null ? null : (int) round($minor / 10 ** Money::digits($from) * $rate * 10 ** Money::digits($to));
    }

    /** How many $to one $from is worth on $on (today when null or not in the past). */
    public function rate(string $from, string $to, ?\DateTimeImmutable $on = null): ?float
    {
        if ($from === $to) {
            return 1.0;
        }
        $today = $this->all();
        $r = $on && $on < new \DateTimeImmutable('today') ? $this->day($on) + $today : $today;

        return isset($r[$from], $r[$to]) ? $r[$from] / $r[$to] : null;
    }

    /**
     * @param array<string, int> $byCurrency
     *
     * @return array{int, list<string>} total in $to at today's rates, and currencies that could not be converted
     */
    public function total(array $byCurrency, string $to): array
    {
        $sum = 0;
        $missing = [];
        foreach ($byCurrency as $cur => $minor) {
            $v = $this->convert($minor, $cur, $to);
            $v === null ? $missing[] = $cur : $sum += $v;
        }

        return [$sum, $missing];
    }

    /**
     * Loads every listed past day in one go (stored ones from the DB, the rest over HTTP in parallel),
     * so converting a year of operations does not fetch day by day.
     *
     * @param iterable<\DateTimeImmutable> $dates
     */
    public function prefetch(iterable $dates): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $need = [];
        foreach ($dates as $d) {
            $k = $d->format('Y-m-d');
            if ($k < $today && !isset($this->days[$k])) {
                $need[$k] = true;
            }
        }
        if (!$need) {
            return;
        }
        foreach ($this->db->fetchAllAssociative('SELECT date, rates FROM money_rate_day WHERE date IN (?)', [array_keys($need)], [ArrayParameterType::STRING]) as $row) {
            $this->days[$row['date']] = json_decode($row['rates'], true);
            unset($need[$row['date']]);
        }
        foreach (array_chunk(array_keys($need), 40) as $chunk) {
            $responses = [];
            foreach ($chunk as $k) {
                $responses[$k] = $this->http->request('GET', \sprintf(self::SNAPSHOT, $k), ['timeout' => 10]);
            }
            foreach ($responses as $k => $response) {
                $rates = $this->snapshot($response) ?? $this->mirror($k) ?? $this->cbrArchive($k);
                if ($rates) {
                    $this->store($k, ...$rates);
                } else {
                    // network trouble: don't remember the gap, today's rates stand in for now
                    $this->days[$k] = [];
                }
            }
        }
    }

    /** @return array<string, float> */
    private function day(\DateTimeImmutable $d): array
    {
        $this->prefetch([$d]);

        return $this->days[$d->format('Y-m-d')] ?? [];
    }

    /** @return array{array<string, float>, string}|null */
    private function snapshot(ResponseInterface $response): ?array
    {
        try {
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return [self::parseSnapshot($response->getContent()), 'snapshot'];
        } catch (\Throwable $e) {
            $this->logger->info('Rate snapshot unavailable: '.$e->getMessage());

            return null;
        }
    }

    /** @return array{array<string, float>, string}|null */
    private function mirror(string $day): ?array
    {
        return $this->snapshot($this->http->request('GET', \sprintf(self::SNAPSHOT_MIRROR, $day), ['timeout' => 10]));
    }

    /**
     * Older days: the Central Bank archive has no file for weekends and holidays, the rate of the last working day applies.
     *
     * @return array{array<string, float>, string}|null
     */
    private function cbrArchive(string $day): ?array
    {
        $d = new \DateTimeImmutable($day);
        for ($i = 0; $i < 10; ++$i, $d = $d->modify('-1 day')) {
            try {
                $response = $this->http->request('GET', \sprintf(self::CBR_ARCHIVE, $d->format('Y/m/d')), ['timeout' => 10]);
                if ($response->getStatusCode() === 200) {
                    return [self::parseCbr($response->getContent()), 'cbr'];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('CBR archive unavailable: '.$e->getMessage());

                return null;
            }
        }

        return null;
    }

    /** @param array<string, float> $rates */
    private function store(string $day, array $rates, string $source): void
    {
        $this->days[$day] = $rates;
        $this->db->executeStatement(
            'INSERT INTO money_rate_day (date, rates, source) VALUES (?, ?, ?) ON CONFLICT (date) DO NOTHING',
            [$day, json_encode($rates, JSON_THROW_ON_ERROR), $source],
        );
    }

    /**
     * Snapshot file: {"rub": {"usd": 0.0121, ...}} = units per one rouble; keeps our currencies only.
     *
     * @return array<string, float>
     */
    public static function parseSnapshot(string $json): array
    {
        $perRub = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['rub'];
        $rates = ['RUB' => 1.0];
        foreach (array_keys(Money::choices()) as $code) {
            $v = $perRub[strtolower($code)] ?? 0;
            if ($v > 0) {
                $rates[$code] = 1 / $v;
            }
        }

        return $rates;
    }

    /** @return array<string, float> */
    public static function parseCbr(string $json): array
    {
        $rates = ['RUB' => 1.0];
        foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR)['Valute'] as $code => $v) {
            $rates[$code] = $v['Value'] / $v['Nominal'];
        }

        return $rates;
    }

    /** @return array<string, float> */
    private function crypto(): array
    {
        return $this->cache->get('crypto_rates', function (ItemInterface $item): array {
            $item->expiresAfter(3600);
            $ids = array_column(Money::CRYPTO, 2);
            try {
                $json = $this->http->request('GET', 'https://api.coingecko.com/api/v3/simple/price', [
                    'query' => ['ids' => implode(',', $ids), 'vs_currencies' => 'rub'],
                    'timeout' => 5,
                ])->getContent();
                $prices = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                $rates = [];
                foreach (Money::CRYPTO as $code => [, , $id]) {
                    if (isset($prices[$id]['rub'])) {
                        $rates[$code] = (float) $prices[$id]['rub'];
                    }
                }

                return $rates;
            } catch (\Throwable $e) {
                $this->logger->warning('Crypto rates unavailable: '.$e->getMessage());
                $item->expiresAfter(300);

                return [];
            }
        });
    }

    /** @return array<string, float> */
    private function fiat(): array
    {
        return $this->cache->get('cbr_rates', function (ItemInterface $item): array {
            $item->expiresAfter(6 * 3600);
            try {
                return self::parseCbr($this->http->request('GET', 'https://www.cbr-xml-daily.ru/daily_json.js', ['timeout' => 5])->getContent());
            } catch (\Throwable $e) {
                $this->logger->warning('CBR rates unavailable: '.$e->getMessage());
                $item->expiresAfter(300);

                return ['RUB' => 1.0];
            }
        });
    }
}
