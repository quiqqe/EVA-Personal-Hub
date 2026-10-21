<?php

declare(strict_types=1);

namespace App\Money\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Rates of one past day, stored once: history does not change. */
#[ORM\Entity]
#[ORM\Table(name: 'money_rate_day')]
class RateDay
{
    public function __construct(
        #[ORM\Id, ORM\Column(type: 'date_immutable')]
        public \DateTimeImmutable $date,
        /** @var array<string, float> RUB per one unit */
        #[ORM\Column(type: 'json')]
        public array $rates,
        #[ORM\Column(length: 20)]
        public string $source,
    ) {
    }
}
