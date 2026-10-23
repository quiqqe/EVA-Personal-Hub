<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Entity\User;
use App\Telegram;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where alerts go: the chat from TELEGRAM_ADMIN_CHAT_ID if set (e.g. a group), otherwise every administrator's
 * own Telegram. Never throws — an alert must not break what triggered it.
 */
final readonly class Alerts
{
    public function __construct(private Telegram $telegram, private EntityManagerInterface $em)
    {
    }

    public function send(string $text): bool
    {
        if ($this->telegram->alert($text)) {
            return true;
        }
        $sent = false;
        try {
            foreach ($this->em->getRepository(User::class)->findBy(['admin' => true]) as $admin) {
                if ($admin->telegramId) {
                    $sent = $this->telegram->send($admin->telegramId, $text) || $sent;
                }
            }
        } catch (\Throwable) {
            // the database may be what is broken
        }

        return $sent;
    }
}
