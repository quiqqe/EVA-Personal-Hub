<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One-time "set a new password" link, sent to the user's Telegram. Only its hash is stored. */
#[ORM\Entity]
#[ORM\Table(name: 'password_reset')]
#[ORM\Index(columns: ['created_at'])]
class PasswordReset
{
    public const TTL = 1800;

    #[ORM\Id, ORM\Column(length: 64)]
    public string $tokenHash;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    /** the plain token, only in memory right after creation (goes into the link) */
    public string $token;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $user,
    ) {
        $this->token = bin2hex(random_bytes(24));
        $this->tokenHash = self::hash($this->token);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function expired(): bool
    {
        return time() - $this->createdAt->getTimestamp() > self::TTL;
    }
}
