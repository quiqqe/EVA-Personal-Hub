<?php

declare(strict_types=1);

namespace App\Money\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Saving towards a target; progress is the balance of the linked account. */
#[ORM\Entity]
#[ORM\Table(name: 'money_goal')]
class Goal
{
    use Scoped;

    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $deadline = null;

    public function __construct(
        #[ORM\Column(length: 100)]
        public string $name,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Account $account,
        #[ORM\Column(type: 'bigint')]
        public int $target,
    ) {
        $this->id = Uuid::v7();
    }
}
