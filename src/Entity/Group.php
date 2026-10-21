<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** People who share things across tools: a family, friends, flatmates. */
#[ORM\Entity]
#[ORM\Table(name: 'groups')]
class Group
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    public string $inviteToken;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    /** @var Collection<int, Membership> */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'group')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $memberships;

    public function __construct(
        #[ORM\Column(length: 100)]
        public string $name,
        #[ORM\Column(length: 10)]
        public string $currency = 'RUB',
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
        $this->newInviteToken();
    }

    public function newInviteToken(): void
    {
        $this->inviteToken = bin2hex(random_bytes(16));
    }

    public function membershipOf(User $user): ?Membership
    {
        foreach ($this->memberships as $m) {
            if ($m->user === $user) {
                return $m;
            }
        }

        return null;
    }

    /** @return list<User> */
    public function members(): array
    {
        return array_values(array_map(fn (Membership $m) => $m->user, $this->memberships->toArray()));
    }
}
