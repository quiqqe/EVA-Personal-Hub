<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

abstract class BaseController extends AbstractController
{
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
