<?php

declare(strict_types=1);

namespace App\Money\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'money_account')]
class Account
{
    use Scoped;

    public const TYPES = [
        'cash' => '💵 Наличные',
        'card' => '💳 Карта',
        'bank' => '🏦 Банковский счёт',
        'savings' => '🐷 Накопления / вклад',
        'credit' => '🧾 Кредитка / кредит',
        'investment' => '📈 Инвестиции',
        'debt' => '🤝 Долги',
        'other' => '📦 Прочее',
    ];

    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 100)]
    public string $name = '';

    #[ORM\Column(length: 20)]
    public string $type = 'card';

    #[ORM\Column(length: 10)]
    public string $currency = 'RUB';

    #[ORM\Column(type: 'bigint')]
    public int $initialBalance = 0;

    #[ORM\Column]
    public bool $archived = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function icon(): string
    {
        return explode(' ', self::TYPES[$this->type] ?? '📦')[0];
    }

    /** Name with the owner, for lists that mix personal and group accounts. */
    public function label(): string
    {
        return $this->name.' ('.($this->group->name ?? $this->user?->name).')';
    }
}
