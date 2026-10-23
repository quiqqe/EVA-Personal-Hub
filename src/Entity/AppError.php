<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A server error, grouped: the same message at the same place is one row with a counter. Kept 90 days. */
#[ORM\Entity]
#[ORM\Table(name: 'app_error')]
#[ORM\Index(columns: ['last_seen'])]
class AppError
{
    /** md5 of message + place */
    #[ORM\Id, ORM\Column(length: 32)]
    public string $hash;

    #[ORM\Column(length: 20)]
    public string $level;

    #[ORM\Column(type: 'text')]
    public string $message;

    /** exception class at file:line, or the log channel */
    #[ORM\Column(length: 255)]
    public string $place;

    /** the request that hit it last */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $url = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $trace = null;

    #[ORM\Column]
    public int $count = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    public \DateTimeImmutable $firstSeen;

    #[ORM\Column(type: 'datetimetz_immutable')]
    public \DateTimeImmutable $lastSeen;

    /** marked as fixed; happening again reopens it */
    #[ORM\Column(options: ['default' => false])]
    public bool $resolved = false;
}
