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
        // where alerts go (errors, server events): a private chat or a group with the bot; empty = no alerts
        #[Autowire(env: 'default::TELEGRAM_ADMIN_CHAT_ID')] private ?string $adminChat = null,
    ) {
    }

    /** A message to the admin chat. Never throws: an alert must not break what triggered it. */
    public function alert(string $text): bool
    {
        return $this->adminChat ? $this->send($this->adminChat, $text) : false;
    }

    /** A plain message to a chat; false instead of an exception. */
    public function send(int|string $chat, string $text): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        try {
            return (bool) ($this->call('sendMessage', ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => true])['ok'] ?? false);
        } catch (\Throwable) {
            return false;
        }
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
     * @return array{kind: 'start'|'button'|'chatid', tgId: int, name: string, chatId: int, param: string, callbackId?: string, messageId?: int}|null
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
        if (isset($m['chat']['id']) && preg_match('/^\/chatid(?:@\w+)?\s*$/', (string) ($m['text'] ?? ''))) {
            return ['kind' => 'chatid', 'tgId' => (int) ($m['from']['id'] ?? 0), 'name' => '', 'chatId' => (int) $m['chat']['id'], 'param' => ''];
        }
        if (!isset($m['from']['id'], $m['chat']['id']) || ($m['chat']['type'] ?? '') !== 'private'
            || !preg_match('/^\/start(?:@\w+)?(?:\s+([A-Za-z0-9_-]{1,64}))?\s*$/', (string) ($m['text'] ?? ''), $mm)) {
            return null;
        }

        return ['kind' => 'start', 'tgId' => (int) $m['from']['id'], 'name' => self::name($m['from']), 'chatId' => (int) $m['chat']['id'], 'param' => $mm[1] ?? ''];
    }

    /** The person who opened the Mini App, if Telegram really signed this initData (and not too long ago). */
    public function webAppUser(string $initData): ?array
    {
        return self::verifyWebApp($initData, $this->token, time());
    }

    /**
     * Mini App sign-in check (core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app):
     * hash = HMAC(sorted "key=value" lines, key = HMAC(bot token, "WebAppData")).
     *
     * @return array{tgId: int, name: string}|null
     */
    public static function verifyWebApp(string $initData, string $token, int $now): ?array
    {
        parse_str($initData, $data);
        $hash = (string) ($data['hash'] ?? '');
        unset($data['hash']);
        if ($token === '' || $hash === '' || !isset($data['user'], $data['auth_date'])) {
            return null;
        }
        ksort($data);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($data), $data));
        $expected = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));
        // a day-old launch is still fine (the app may stay open); older data is not accepted again
        if (!hash_equals($expected, $hash) || $now - (int) $data['auth_date'] > 86400) {
            return null;
        }
        $user = json_decode((string) $data['user'], true);

        return isset($user['id']) ? ['tgId' => (int) $user['id'], 'name' => self::name($user)] : null;
    }

    private static function name(array $from): string
    {
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: ($from['username'] ?? 'Telegram');

        return mb_substr($name, 0, 100);
    }
}
