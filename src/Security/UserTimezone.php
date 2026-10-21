<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Extension\CoreExtension;

/** For the rest of the request, "today", month boundaries and displayed times are in the signed-in person's time zone. */
#[AsEventListener(event: KernelEvents::REQUEST, priority: -10)]
final readonly class UserTimezone
{
    public function __construct(private Security $security, private Environment $twig)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !\in_array($user->timezone, \DateTimeZone::listIdentifiers(), true)) {
            return;
        }
        date_default_timezone_set($user->timezone);
        $this->twig->getExtension(CoreExtension::class)->setTimezone($user->timezone);
    }
}
