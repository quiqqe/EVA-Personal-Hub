<?php

declare(strict_types=1);

namespace App\Money\Entity;

use App\Entity\User;
use App\Money\Schedule;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Template that turns into an operation on every scheduled date. */
#[ORM\Entity]
#[ORM\Table(name: 'money_recurring')]
#[ORM\Index(columns: ['next_date'])]
class Recurring
{
    use Scoped;

    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 10)]
    public string $type = 'expense';

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Account $account = null;

    #[ORM\Column(type: 'bigint')]
    public int $amount = 0;

    /** In another currency than the account's (salary in USDT onto an AMD card): converted at each payment day's rate */
    #[ORM\Column(type: 'bigint', nullable: true)]
    public ?int $origAmount = null;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $origCurrency = null;

    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')]
    public ?Account $toAccount = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    public ?int $toAmount = null;

    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Category $category = null;

    #[ORM\Column(length: 255)]
    public string $note = '';

    #[ORM\Column(length: 10)]
    public string $period = 'month';

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $startDate;

    /** Operations created so far */
    #[ORM\Column]
    public int $count = 0;

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $nextDate;

    #[ORM\Column]
    public bool $active = true;

    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $createdBy = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->startDate = $this->nextDate = new \DateTimeImmutable('today');
    }

    public function advance(): void
    {
        $this->nextDate = Schedule::occurrence($this->startDate, $this->period, ++$this->count);
    }
}
