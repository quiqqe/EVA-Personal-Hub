<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\Group;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

abstract class BaseController extends AbstractController
{
    use TargetPathTrait;

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + ['doctrine' => '?'.ManagerRegistry::class];
    }

    /** Too many sign-up / reset / Telegram attempts from one address: slow down brute force and spam. */
    protected static function tooFast(Request $r, RateLimiterFactoryInterface $limiter): bool
    {
        return !$limiter->create('ip-'.$r->getClientIp())->consume()->isAccepted();
    }

    /** Where to go right after signing in: the page that asked for it (an invite link), else the hub. */
    protected function afterLogin(Request $r): string
    {
        $target = $this->getTargetPath($r->getSession(), 'main');
        $this->removeTargetPath($r->getSession(), 'main');

        return $target && str_starts_with((string) parse_url($target, PHP_URL_PATH), '/') ? $target : $this->generateUrl('hub');
    }

    /** The group an unsigned visitor was invited to, to say so on the sign-in pages. */
    protected function invitedTo(Request $r): ?Group
    {
        $target = (string) $this->getTargetPath($r->getSession(), 'main');
        if (!preg_match('#/join/([0-9a-f]{32})#', $target, $m)) {
            return null;
        }

        return $this->container->get('doctrine')->getRepository(Group::class)->findOneBy(['inviteToken' => $m[1]]);
    }
    protected function me(): User
    {
        $u = $this->getUser();
        \assert($u instanceof User);

        return $u;
    }

    /** Every POST form carries csrf_token('form'). */
    protected function checkCsrf(Request $r): void
    {
        if (!$this->isCsrfTokenValid('form', (string) $r->request->get('_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF token');
        }
    }

    protected function back(Request $r, string $fallback): string
    {
        $ref = (string) $r->headers->get('referer');

        return $ref !== '' && parse_url($ref, PHP_URL_HOST) === $r->getHost() ? $ref : $fallback;
    }
}
