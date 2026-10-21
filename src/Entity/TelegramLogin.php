<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One "sign in / link with Telegram" attempt. The browser that started it keeps the code in its session;
 * the person confirms in the bot; then that browser (and only it) is signed in. Valid for 10 minutes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'telegram_login')]
class TelegramLogin
{
    public const TTL = 600;

    #[ORM\Id, ORM\Column(length: 32)]
    public string $code;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    /** Who pressed Start in the bot (set on /start, must match the one who confirms) */
    #[ORM\Column(type: 'bigint', nullable: true)]
    public ?int $telegramId = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $telegramName = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $confirmedAt = null;

    public function __construct(
        /** null = sign in / sign up; a user = link Telegram to that account */
        #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')]
        public ?User $linkTo,
        /** shown in the bot so the person can tell it is their own request */
        #[ORM\Column(length: 200)]
        public string $requestedFrom,
    ) {
        $this->code = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function expired(): bool
    {
        return time() - $this->createdAt->getTimestamp() > self::TTL;
    }
}
