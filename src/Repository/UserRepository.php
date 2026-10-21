<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/** @extends ServiceEntityRepository<User> */
final class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** "tg:123" for people who signed in with Telegram only, otherwise the email. */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return str_starts_with($identifier, 'tg:')
            ? $this->findOneBy(['telegramId' => (int) substr($identifier, 3)])
            : $this->findOneBy(['email' => mb_strtolower($identifier)]);
    }
}
