<?php

declare(strict_types=1);

namespace App\Money\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A member's part in a group expense: what they owe (amount) and what they paid towards it. */
#[ORM\Entity]
#[ORM\Table(name: 'money_split')]
class Split
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'splits'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Operation $operation,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $user,
        /** Owed share */
        #[ORM\Column(type: 'bigint')]
        public int $amount,
        #[ORM\Column(type: 'bigint', options: ['default' => 0])]
        public int $paid = 0,
        /** What was typed for percent / shares splits, to show it again when editing */
        #[ORM\Column(nullable: true)]
        public ?float $weight = null,
    ) {
        $this->id = Uuid::v7();
    }
}
