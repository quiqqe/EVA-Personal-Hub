<?php

declare(strict_types=1);

namespace App\Money;

use App\Entity\Group;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** {scope} in the URL → Scope; a group you are not a member of is a 404. Opening a space also runs its due recurring operations. */
// above the built-in request attribute resolver, which would pass {scope} as a plain string
#[AutoconfigureTag('controller.argument_value_resolver', ['priority' => 150])]
final class ScopeResolver implements ValueResolverInterface
{
    public function __construct(private readonly Security $security, private readonly EntityManagerInterface $em, private readonly Ledger $ledger)
    {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== Scope::class) {
            return [];
        }
        $me = $this->security->getUser();
        \assert($me instanceof User);
        $key = (string) $request->attributes->get('scope');
        if ($key === 'me') {
            $scope = new Scope($me);
        } else {
            $group = Uuid::isValid($key) ? $this->em->find(Group::class, $key) : null;
            if (!$group?->membershipOf($me)) {
                throw new NotFoundHttpException();
            }
            $scope = new Scope($me, $group);
        }
        $this->ledger->open($scope);

        return [$scope];
    }
}
