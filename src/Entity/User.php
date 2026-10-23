<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    /** Empty for people who only use Telegram */
    #[ORM\Column]
    public string $password = '';

    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    public ?int $telegramId = null;

    /** "Today" and month boundaries are the person's, not the server's */
    #[ORM\Column(length: 64, options: ['default' => 'Europe/Moscow'])]
    public string $timezone = 'Europe/Moscow';

    /** Follows the browser until the person picks a zone in the profile */
    #[ORM\Column(options: ['default' => true])]
    public bool $timezoneAuto = true;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    /** Site administrator: sees the admin page and gets alerts in Telegram. Granted only from the console (app:admin). */
    #[ORM\Column(options: ['default' => false])]
    public bool $admin = false;

    public function __construct(
        #[ORM\Column(length: 180, unique: true, nullable: true)]
        public ?string $email,
        #[ORM\Column(length: 100)]
        public string $name,
        /** Base currency for totals in the personal space */
        #[ORM\Column(length: 10)]
        public string $currency = 'RUB',
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? 'tg:'.$this->telegramId;
    }

    public function getRoles(): array
    {
        return $this->admin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }
}
