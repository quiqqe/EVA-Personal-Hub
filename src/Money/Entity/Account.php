<?php

declare(strict_types=1);

namespace App\Money\Entity;

use App\Entity\Group;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /**
     * Groups that see this personal account: its balance and operations count in the group's "common money".
     *
     * @var Collection<int, Group>
     */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'money_account_shared')]
    public Collection $sharedWith;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->sharedWith = new ArrayCollection();
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
