<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PasswordReset;
use App\Repository\UserRepository;
use App\Telegram;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/** Forgotten password: the reset link goes to the account's Telegram (the server sends no e-mail). */
final class PasswordResetController extends BaseController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly Telegram $telegram,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/password/forgot', name: 'password_forgot')]
    public function forgot(Request $r, RateLimiterFactoryInterface $authLimiter): Response
    {
        $sent = false;
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            if (self::tooFast($r, $authLimiter)) {
                return $this->render('auth/forgot.html.twig', ['sent' => false, 'error' => 'Слишком много попыток. Подождите 15 минут']);
            }
            // old unused links are of no use to anyone
            $this->em->createQuery('DELETE FROM '.PasswordReset::class.' p WHERE p.createdAt < :old')->setParameter('old', new \DateTimeImmutable('-1 day'))->execute();
            $user = $this->users->findOneBy(['email' => mb_strtolower(trim((string) $r->request->get('email')))]);
            $recent = $user ? $this->em->createQuery('SELECT COUNT(p.tokenHash) FROM '.PasswordReset::class.' p WHERE p.user = :u AND p.createdAt > :since')
                ->setParameter('u', $user)->setParameter('since', new \DateTimeImmutable('-2 minutes'))->getSingleScalarResult() : 0;
            if ($user?->telegramId && !$recent && $this->telegram->enabled()) {
                $reset = new PasswordReset($user);
                $this->em->persist($reset);
                $this->em->flush();
                $link = $this->generateUrl('password_reset', ['token' => $reset->token], UrlGeneratorInterface::ABSOLUTE_URL);
                try {
                    $this->telegram->call('sendMessage', [
                        'chat_id' => $user->telegramId,
                        'text' => "Сброс пароля EVA Personal.\n\nЗапрос: ".TelegramController::describe($r)
                            ."\nСсылка действует 30 минут и сработает один раз. Если это не вы — просто проигнорируйте, пароль не изменится.",
                        'reply_markup' => ['inline_keyboard' => [[['text' => '🔑 Задать новый пароль', 'url' => $link]]]],
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('Password reset message failed: '.$e->getMessage());
                }
            }
            // the same answer whether the e-mail exists or not
            $sent = true;
        }

        return $this->render('auth/forgot.html.twig', ['sent' => $sent]);
    }

    #[Route('/password/reset/{token}', name: 'password_reset', requirements: ['token' => '[0-9a-f]{48}'])]
    public function reset(Request $r, string $token, UserPasswordHasherInterface $hasher, Security $security): Response
    {
        $reset = $this->em->find(PasswordReset::class, PasswordReset::hash($token));
        if (!$reset || $reset->expired()) {
            return $this->render('auth/reset.html.twig', ['valid' => false, 'error' => null]);
        }
        $error = null;
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $password = (string) $r->request->get('password');
            if (mb_strlen($password) < 8) {
                $error = 'Пароль — минимум 8 символов';
            } else {
                $user = $reset->user;
                $user->password = $hasher->hashPassword($user, $password);
                // every link of this user dies with the first use
                $this->em->createQuery('DELETE FROM '.PasswordReset::class.' p WHERE p.user = :u')->setParameter('u', $user)->execute();
                $this->em->flush();
                $security->login($user, 'form_login', 'main', [(new RememberMeBadge())->enable()]);
                $this->addFlash('ok', 'Пароль изменён');

                return $this->redirectToRoute('hub');
            }
        }

        return $this->render('auth/reset.html.twig', ['valid' => true, 'error' => $error]);
    }
}
