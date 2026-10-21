<?php

declare(strict_types=1);

namespace App\Money\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A member's share of a group expense. */
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
        #[ORM\Column(type: 'bigint')]
        public int $amount,
    ) {
        $this->id = Uuid::v7();
    }
}
