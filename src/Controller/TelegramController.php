<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\TelegramAuth;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/** Sign in / sign up with the Telegram Login Widget, and linking Telegram to an existing account. */
final class TelegramController extends BaseController
{
    public function __construct(
        private readonly TelegramAuth $telegram,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/login/telegram', name: 'login_telegram', methods: ['GET'])]
    public function login(Request $r, Security $security): Response
    {
        $tg = $this->telegram->verify($r->query->all());
        if (!$tg) {
            $this->addFlash('error', 'Не удалось войти через Telegram — попробуйте ещё раз');

            return $this->redirectToRoute('login');
        }
        $user = $this->users->findOneBy(['telegramId' => $tg['id']]);
        if (!$user) {
            // first visit through Telegram is the sign-up
            $user = new User(null, $tg['name']);
            $user->telegramId = $tg['id'];
            $this->em->persist($user);
            $this->em->flush();
        }
        $security->login($user, 'form_login', 'main', [(new RememberMeBadge())->enable()]);

        return $this->redirectToRoute('hub');
    }

    #[Route('/profile/telegram', name: 'profile_telegram_link', methods: ['GET'])]
    public function link(Request $r): Response
    {
        $tg = $this->telegram->verify($r->query->all());
        $owner = $tg ? $this->users->findOneBy(['telegramId' => $tg['id']]) : null;
        if (!$tg) {
            $this->addFlash('error', 'Не удалось привязать Telegram — попробуйте ещё раз');
        } elseif ($owner && $owner !== $this->me()) {
            $this->addFlash('error', 'Этот Telegram уже привязан к другому аккаунту');
        } else {
            $this->me()->telegramId = $tg['id'];
            $this->em->flush();
            $this->addFlash('ok', 'Telegram привязан — теперь можно входить в один тап');
        }

        return $this->redirectToRoute('profile');
    }

    #[Route('/profile/telegram/unlink', name: 'profile_telegram_unlink', methods: ['POST'])]
    public function unlink(Request $r): Response
    {
        $this->checkCsrf($r);
        $me = $this->me();
        // without email and password the account would be locked
        if (!$me->email || $me->password === '') {
            $this->addFlash('error', 'Сначала задайте email и пароль — иначе войти будет нечем');
        } else {
            $me->telegramId = null;
            $this->em->flush();
            $this->addFlash('ok', 'Telegram отвязан');
        }

        return $this->redirectToRoute('profile');
    }
}
