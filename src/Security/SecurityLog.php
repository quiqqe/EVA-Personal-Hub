<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** Writes the security journal (sign-ins, failures, password and Telegram changes). Never breaks the request. */
final readonly class SecurityLog
{
    public const KEEP_DAYS = 30;

    public function __construct(private EntityManagerInterface $em, private RequestStack $requests)
    {
    }

    /** An event from the server's scripts (no request, no user). */
    public function server(string $text, string $ip = ''): void
    {
        $this->insert('server', null, $text, $ip, 'сервер');
    }

    public function record(string $type, ?User $user = null, ?string $detail = null): void
    {
        $r = $this->requests->getMainRequest();
        $this->insert($type, $user, $detail, (string) $r?->getClientIp(), $r ? self::device($r) : 'консоль');
    }

    private function insert(string $type, ?User $user, ?string $detail, string $ip, string $device): void
    {
        try {
            // a user that is not saved yet (or was removed) cannot be referenced
            $known = $user && $this->em->contains($user) ? $user : null;
            // a plain insert, not persist/flush: must not flush someone else's half-done changes mid-request
            $this->em->getConnection()->insert('security_event', [
                'id' => Uuid::v7()->toRfc4122(),
                'type' => $type,
                'user_id' => $known?->id->toRfc4122(),
                'ip' => mb_substr($ip, 0, 45),
                'device' => $device,
                'detail' => $detail !== null ? mb_substr($detail, 0, 200) : null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'),
            ]);
            // ponytail: purge on ~1% of writes instead of a cron; fine at this traffic
            if (random_int(1, 100) === 1) {
                $this->em->getConnection()->executeStatement('DELETE FROM security_event WHERE created_at < ?', [(new \DateTimeImmutable('-'.self::KEEP_DAYS.' days'))->format('Y-m-d H:i:sP')]);
            }
        } catch (\Throwable) {
            // the journal is best-effort
        }
    }

    /** "Chrome, macOS" — the same wording the Telegram bot uses. */
    public static function device(Request $r): string
    {
        $ua = (string) $r->headers->get('User-Agent');
        $browser = match (true) {
            str_contains($ua, 'Telegram') => 'Telegram',
            str_contains($ua, 'YaBrowser') => 'Яндекс Браузер',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'curl') || str_contains($ua, 'python') || $ua === '' => 'скрипт',
            default => 'браузер',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return $browser.($os ? ", $os" : '');
    }
}
