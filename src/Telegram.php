<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The site's Telegram bot: Bot API calls and reading incoming updates. */
final readonly class Telegram
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(env: 'TELEGRAM_BOT_TOKEN')] private string $token,
        #[Autowire(env: 'TELEGRAM_BOT_USERNAME')] public string $botUsername,
    ) {
    }

    public function enabled(): bool
    {
        return $this->token !== '' && $this->botUsername !== '';
    }

    /** Telegram sends it back in a header with every webhook call; derived from the token, so nothing new to configure. */
    public function webhookSecret(): string
    {
        return hash_hmac('sha256', 'webhook', $this->token);
    }

    /** @param array<string, mixed> $params */
    public function call(string $method, array $params = []): array
    {
        return $this->http->request('POST', "https://api.telegram.org/bot{$this->token}/$method", ['json' => $params, 'timeout' => 10])
            ->toArray(false);
    }

    /**
     * The parts of an update we act on:
     * "/start <code>" in a private chat, or a press on an inline button.
     *
     * @return array{kind: 'start'|'button', tgId: int, name: string, chatId: int, param: string, callbackId?: string, messageId?: int}|null
     */
    public static function parse(array $update): ?array
    {
        if (isset($update['callback_query']['from']['id'], $update['callback_query']['message']['chat']['id'])) {
            $q = $update['callback_query'];

            return [
                'kind' => 'button',
                'tgId' => (int) $q['from']['id'],
                'name' => self::name($q['from']),
                'chatId' => (int) $q['message']['chat']['id'],
                'param' => (string) ($q['data'] ?? ''),
                'callbackId' => (string) $q['id'],
                'messageId' => (int) $q['message']['message_id'],
            ];
        }
        $m = $update['message'] ?? null;
        if (!isset($m['from']['id'], $m['chat']['id']) || ($m['chat']['type'] ?? '') !== 'private'
            || !preg_match('/^\/start(?:@\w+)?(?:\s+([A-Za-z0-9_-]{1,64}))?\s*$/', (string) ($m['text'] ?? ''), $mm)) {
            return null;
        }

        return ['kind' => 'start', 'tgId' => (int) $m['from']['id'], 'name' => self::name($m['from']), 'chatId' => (int) $m['chat']['id'], 'param' => $mm[1] ?? ''];
    }

    private static function name(array $from): string
    {
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: ($from['username'] ?? 'Telegram');

        return mb_substr($name, 0, 100);
    }
}
