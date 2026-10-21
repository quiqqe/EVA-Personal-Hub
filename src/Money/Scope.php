<?php

declare(strict_types=1);

namespace App\Money;

use App\Entity\Group;
use App\Entity\User;

/** Where money lives: my personal space, or a group shared by its members. Resolved from {scope} in the URL. */
final readonly class Scope
{
    public function __construct(public User $me, public ?Group $group = null)
    {
    }

    public function key(): string
    {
        return $this->group ? (string) $this->group->id : 'me';
    }

    public function name(): string
    {
        return $this->group->name ?? 'Личное';
    }

    public function currency(): string
    {
        return $this->group->currency ?? $this->me->currency;
    }

    /** Entity using the Scoped trait belongs here. */
    public function owns(object $e): bool
    {
        return $this->group ? $e->group === $this->group : ($e->group === null && $e->user === $this->me);
    }

    /** @return list<\App\Entity\User> */
    public function members(): array
    {
        return $this->group ? $this->group->members() : [$this->me];
    }
}
