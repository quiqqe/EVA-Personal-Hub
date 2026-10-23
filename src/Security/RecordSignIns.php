<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Sign-ins, failed attempts and sign-outs → the security journal. */
final readonly class RecordSignIns
{
    public function __construct(private SecurityLog $log, private \App\Repository\UserRepository $users)
    {
    }

    #[AsEventListener]
    public function onSuccess(LoginSuccessEvent $e): void
    {
        $user = $e->getUser();
        // "remember me" re-entering a known session is not a new sign-in
        if (!$user instanceof User || $e->getAuthenticator() instanceof RememberMeAuthenticator) {
            return;
        }
        $path = $e->getRequest()->getPathInfo();
        $how = match (true) {
            str_contains($path, 'webapp') => 'Mini App',
            str_contains($path, 'telegram') => 'Telegram',
            str_contains($path, 'register') => 'после регистрации',
            str_contains($path, 'password/reset') => 'после сброса пароля',
            default => 'пароль',
        };
        $this->log->record('login', $user, $how);
    }

    #[AsEventListener]
    public function onFailure(LoginFailureEvent $e): void
    {
        $email = mb_strtolower(trim((string) $e->getRequest()->request->get('email')));
        // tied to the account when the e-mail exists: its owner sees someone tried
        $this->log->record(
            $e->getException() instanceof TooManyLoginAttemptsAuthenticationException ? 'rate_limited' : 'login_failed',
            $email !== '' ? $this->users->findOneBy(['email' => $email]) : null,
            $email ?: null,
        );
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $e): void
    {
        $user = $e->getToken()?->getUser();
        $this->log->record('logout', $user instanceof User ? $user : null);
    }
}
