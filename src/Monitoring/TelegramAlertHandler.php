<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Telegram;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Errors of the site → the admin Telegram chat. A 4xx (bots probing, wrong links) is not an error;
 * the same error repeated is sent once per 10 minutes.
 */
final class TelegramAlertHandler extends AbstractProcessingHandler
{
    public function __construct(private readonly Telegram $telegram, private readonly CacheInterface $cache, private readonly RequestStack $requests)
    {
        parent::__construct(Level::Error);
    }

    protected function write(LogRecord $record): void
    {
        $e = $record->context['exception'] ?? null;
        if (($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) || $record->channel === 'http_client') {
            return;
        }
        $where = $e instanceof \Throwable ? $e::class.' at '.basename($e->getFile()).':'.$e->getLine() : $record->channel;
        $fresh = false;
        $this->cache->get('alert_'.md5($record->message.$where), function (ItemInterface $item) use (&$fresh) {
            $item->expiresAfter(600);
            $fresh = true;

            return 1;
        });
        if (!$fresh) {
            return;
        }
        $req = $this->requests->getMainRequest();
        $this->telegram->alert("🔴 Ошибка на сайте\n".mb_substr($record->message, 0, 1500)."\n$where"
            .($req ? "\n".$req->getMethod().' '.$req->getPathInfo() : ''));
    }
}
