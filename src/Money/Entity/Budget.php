<?php

declare(strict_types=1);

namespace App\Money\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Monthly limit on an expense category; spending in any currency is converted into the budget's. */
#[ORM\Entity]
#[ORM\Table(name: 'money_budget')]
class Budget
{
    use Scoped;

    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    public function __construct(
        #[ORM\OneToOne, ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        public Category $category,
        #[ORM\Column(type: 'bigint')]
        public int $amount,
        #[ORM\Column(length: 10, options: ['default' => 'RUB'])]
        public string $currency = 'RUB',
    ) {
        $this->id = Uuid::v7();
    }
}
