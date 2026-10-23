<?php

declare(strict_types=1);

namespace App\Monitoring;

use Doctrine\DBAL\Connection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Every server error (not a 4xx — those are bots and wrong links): into the error journal (admin → «Ошибки»,
 * the same one grouped with a counter) and to the administrators' Telegram — the same one at most every 10 minutes.
 */
final class TelegramAlertHandler extends AbstractProcessingHandler
{
    public const KEEP_DAYS = 90;

    public function __construct(
        private readonly Alerts $alerts,
        private readonly CacheInterface $cache,
        private readonly RequestStack $requests,
        private readonly ?Connection $db = null,
        private readonly ?UrlGeneratorInterface $urls = null,
    ) {
        parent::__construct(Level::Error);
    }

    protected function write(LogRecord $record): void
    {
        $e = $record->context['exception'] ?? null;
        if (($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) || $record->channel === 'http_client') {
            return;
        }
        $place = mb_substr($e instanceof \Throwable ? $e::class.' at '.basename($e->getFile()).':'.$e->getLine() : $record->channel, 0, 255);
        $hash = md5($record->message.$place);
        $req = $this->requests->getMainRequest();
        $url = $req ? mb_substr($req->getMethod().' '.$req->getPathInfo(), 0, 255) : null;
        $this->remember($hash, $record, $place, $url, $e instanceof \Throwable ? mb_substr($e->getTraceAsString(), 0, 8000) : null);

        $fresh = false;
        $this->cache->get('alert_'.$hash, function (ItemInterface $item) use (&$fresh) {
            $item->expiresAfter(600);
            $fresh = true;

            return 1;
        });
        if ($fresh) {
            $link = $this->urls?->generate('admin_error', ['hash' => $hash], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->alerts->send("🔴 Ошибка на сайте\n".mb_substr($record->message, 0, 1500)."\n$place".($url ? "\n$url" : '').($link ? "\n$link" : ''));
        }
    }

    /** One row per distinct error; a repeat bumps the counter and reopens it if it was marked fixed. */
    private function remember(string $hash, LogRecord $record, string $place, ?string $url, ?string $trace): void
    {
        if (!$this->db) {
            return;
        }
        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
            $this->db->executeStatement(
                'INSERT INTO app_error (hash, level, message, place, url, trace, count, first_seen, last_seen, resolved)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, false)
                 ON CONFLICT (hash) DO UPDATE SET count = app_error.count + 1, last_seen = EXCLUDED.last_seen,
                    url = EXCLUDED.url, trace = COALESCE(EXCLUDED.trace, app_error.trace), resolved = false',
                [$hash, $record->level->getName(), mb_substr($record->message, 0, 5000), $place, $url, $trace, $now, $now],
            );
            if (random_int(1, 100) === 1) {
                $this->db->executeStatement('DELETE FROM app_error WHERE last_seen < ?', [(new \DateTimeImmutable('-'.self::KEEP_DAYS.' days'))->format('Y-m-d H:i:sP')]);
            }
        } catch (\Throwable) {
            // the database may be what is broken: Telegram still gets it
        }
    }
}
