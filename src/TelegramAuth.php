<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Telegram Login Widget: checks that the data really comes from Telegram for our bot. */
final readonly class TelegramAuth
{
    public function __construct(
        #[Autowire(env: 'TELEGRAM_BOT_TOKEN')] private string $token,
        #[Autowire(env: 'TELEGRAM_BOT_USERNAME')] public string $botUsername,
    ) {
    }

    public function enabled(): bool
    {
        return $this->token !== '' && $this->botUsername !== '';
    }

    /**
     * @param array<string, string> $data query from the widget redirect
     *
     * @return array{id: int, name: string}|null
     */
    public function verify(array $data, ?int $now = null): ?array
    {
        return $this->enabled() ? self::check($data, $this->token, $now ?? time()) : null;
    }

    /**
     * https://core.telegram.org/widgets/login#checking-authorization
     *
     * @param array<string, string> $data
     *
     * @return array{id: int, name: string}|null
     */
    public static function check(array $data, string $token, int $now): ?array
    {
        $hash = (string) ($data['hash'] ?? '');
        $fields = array_intersect_key($data, array_flip(['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date']));
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));
        $expected = hash_hmac('sha256', $check, hash('sha256', $token, true));
        // a login link is good for a day, so an old one can't be replayed
        if ($hash === '' || !hash_equals($expected, $hash) || $now - (int) ($fields['auth_date'] ?? 0) > 86400 || !ctype_digit((string) ($fields['id'] ?? ''))) {
            return null;
        }
        $name = trim(($fields['first_name'] ?? '').' '.($fields['last_name'] ?? '')) ?: ($fields['username'] ?? 'Telegram');

        return ['id' => (int) $fields['id'], 'name' => mb_substr($name, 0, 100)];
    }
}
