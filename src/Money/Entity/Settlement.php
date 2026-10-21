<?php

declare(strict_types=1);

namespace App\Money\Entity;

use App\Entity\Group;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One group member paid back another. */
#[ORM\Entity]
#[ORM\Table(name: 'money_settlement')]
class Settlement
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $date;

    /** Paid back in another currency: what was actually handed over ($amount is in the debt's currency) */
    #[ORM\Column(type: 'bigint', nullable: true)]
    public ?int $origAmount = null;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $origCurrency = null;

    /** The payer's own record of it: an expense on their account (each side attaches their own account) */
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Operation $payerOperation = null;

    /** The receiver's: income on their account */
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Operation $receiverOperation = null;

    /** @return list<Operation> */
    public function operations(): array
    {
        return array_values(array_filter([$this->payerOperation, $this->receiverOperation]));
    }

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Group $group,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $from,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $to,
        #[ORM\Column(type: 'bigint')]
        public int $amount,
        #[ORM\Column(length: 10)]
        public string $currency,
    ) {
        $this->id = Uuid::v7();
        $this->date = new \DateTimeImmutable('today');
    }
}
