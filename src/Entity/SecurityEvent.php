<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One sign-in, failed attempt, password change…: who, what, from where. Kept 30 days. */
#[ORM\Entity]
#[ORM\Table(name: 'security_event')]
#[ORM\Index(columns: ['created_at'])]
class SecurityEvent
{
    public const TYPES = [
        'login' => 'Вход',
        'login_failed' => 'Неудачный вход',
        'rate_limited' => 'Слишком много попыток',
        'logout' => 'Выход',
        'register' => 'Регистрация',
        'reset_requested' => 'Запрошен сброс пароля',
        'password_changed' => 'Пароль изменён',
        'telegram_linked' => 'Telegram привязан',
        'telegram_unlinked' => 'Telegram отвязан',
        'role_changed' => 'Роль изменена',
        // from the server's own scripts (app:server-event): SSH sign-ins, health, bans
        'server' => 'Сервер',
    ];

    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(type: 'datetimetz_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 30)]
        public string $type,
        #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')]
        public ?User $user,
        #[ORM\Column(length: 45)]
        public string $ip,
        /** browser and system, e.g. "Chrome, macOS" */
        #[ORM\Column(length: 100)]
        public string $device,
        /** how (password / Telegram / Mini App) or the e-mail that was tried */
        #[ORM\Column(length: 200, nullable: true)]
        public ?string $detail = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }
}
