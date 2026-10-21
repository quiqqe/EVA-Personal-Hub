<?php

declare(strict_types=1);

namespace App\Money;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Today's rates: Central Bank of Russia for fiat, CoinGecko for crypto. Used for every conversion (no historical rates). */
final class Rates
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, float> RUB per one unit of currency */
    public function all(): array
    {
        return $this->fiat() + $this->crypto();
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
                $json = $this->http->request('GET', 'https://www.cbr-xml-daily.ru/daily_json.js', ['timeout' => 5])->getContent();
                $rates = ['RUB' => 1.0];
                foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR)['Valute'] as $code => $v) {
                    $rates[$code] = $v['Value'] / $v['Nominal'];
                }

                return $rates;
            } catch (\Throwable $e) {
                $this->logger->warning('CBR rates unavailable: '.$e->getMessage());
                $item->expiresAfter(300);

                return ['RUB' => 1.0];
            }
        });
    }

    public function convert(int $minor, string $from, string $to): ?int
    {
        if ($from === $to) {
            return $minor;
        }
        $r = $this->all();
        if (!isset($r[$from], $r[$to])) {
            return null;
        }

        return (int) round($minor / 10 ** Money::digits($from) * $r[$from] / $r[$to] * 10 ** Money::digits($to));
    }

    /**
     * @param array<string, int> $byCurrency
     *
     * @return array{int, list<string>} total in $to, and currencies that could not be converted
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
}
