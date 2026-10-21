<?php

declare(strict_types=1);

namespace App\Money\Entity;

use App\Entity\Group;
use App\Entity\User;
use App\Money\Scope;
use Doctrine\ORM\Mapping as ORM;

/** Owned either by a user (personal space) or by a group. */
trait Scoped
{
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')]
    public ?User $user = null;

    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')]
    public ?Group $group = null;

    public function setScope(Scope $scope): static
    {
        $scope->group ? $this->group = $scope->group : $this->user = $scope->me;

        return $this;
    }
}
