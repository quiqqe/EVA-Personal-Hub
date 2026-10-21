<?php

declare(strict_types=1);

namespace App\Money\Entity;

use App\Entity\Group;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Expense, income or transfer. Amount is positive, in the account's currency.
 * A group expense paid by another member has no account: only its currency and splits.
 */
#[ORM\Entity]
#[ORM\Table(name: 'money_operation')]
#[ORM\Index(columns: ['account_id', 'date'])]
#[ORM\Index(columns: ['to_account_id', 'date'])]
#[ORM\Index(columns: ['group_id', 'date'])]
class Operation
{
    public const TYPES = ['expense' => 'Расход', 'income' => 'Доход', 'transfer' => 'Перевод'];

    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 10)]
    public string $type = 'expense';

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $date;

    #[ORM\ManyToOne]
    public ?Account $account = null;

    #[ORM\Column(type: 'bigint')]
    public int $amount = 0;

    #[ORM\Column(length: 10)]
    public string $currency = 'RUB';

    #[ORM\ManyToOne]
    public ?Account $toAccount = null;

    /** Credited to toAccount, differs from amount when currencies differ */
    #[ORM\Column(type: 'bigint', nullable: true)]
    public ?int $toAmount = null;

    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Category $category = null;

    #[ORM\Column(length: 255)]
    public string $note = '';

    /** Set when created inside a group space */
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Group $group = null;

    /** Who paid a split expense */
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $paidBy = null;

    /** @var Collection<int, Split> */
    #[ORM\OneToMany(targetEntity: Split::class, mappedBy: 'operation', cascade: ['persist'], orphanRemoval: true)]
    public Collection $splits;

    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $createdBy = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    public ?string $importHash = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->date = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->splits = new ArrayCollection();
    }
}
