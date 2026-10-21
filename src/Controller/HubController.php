<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Money\Ledger;
use App\Money\Money;
use App\Money\Rates;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class HubController extends BaseController
{
    #[Route('/', name: 'hub')]
    public function index(Ledger $ledger, Rates $rates): Response
    {
        // a glance at each tool; the money widget shows the personal total
        $me = $this->me();
        $accounts = $ledger->accounts(new Scope($me));
        $balances = $ledger->balances($accounts);
        $byCurrency = [];
        foreach ($accounts as $a) {
            $byCurrency[$a->currency] = ($byCurrency[$a->currency] ?? 0) + $balances[(string) $a->id];
        }

        return $this->render('hub.html.twig', [
            'total' => $rates->total($byCurrency, $me->currency)[0],
            'accounts' => \count($accounts),
            'groups' => $ledger->groupsOf($me),
        ]);
    }

    #[Route('/profile', name: 'profile')]
    public function profile(Request $r, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $me = $this->me();
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $name = trim((string) $r->request->get('name'));
            $currency = (string) $r->request->get('currency');
            $new = (string) $r->request->get('new_password');
            if ($name !== '' && mb_strlen($name) <= 100) {
                $me->name = $name;
            }
            if (isset(Money::choices()[$currency])) {
                $me->currency = $currency;
            }
            // someone who came via Telegram can add an email to sign in with a password too
            $email = mb_strtolower(trim((string) $r->request->get('email')));
            if (!$me->email && $email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $em->getRepository(User::class)->findOneBy(['email' => $email])) {
                    $this->addFlash('error', 'Этот email не подходит или уже занят');

                    return $this->redirectToRoute('profile');
                }
                $me->email = $email;
            }
            if ($new !== '') {
                if ($me->password !== '' && !$hasher->isPasswordValid($me, (string) $r->request->get('password'))) {
                    $this->addFlash('error', 'Текущий пароль неверный');

                    return $this->redirectToRoute('profile');
                }
                if (mb_strlen($new) < 8) {
                    $this->addFlash('error', 'Новый пароль — минимум 8 символов');

                    return $this->redirectToRoute('profile');
                }
                $me->password = $hasher->hashPassword($me, $new);
            }
            $em->flush();
            $this->addFlash('ok', 'Сохранено');

            return $this->redirectToRoute('profile');
        }

        return $this->render('profile.html.twig');
    }

    #[Route('/manifest.webmanifest', name: 'manifest')]
    public function manifest(): Response
    {
        return $this->json([
            'name' => 'EVA Personal',
            'short_name' => 'EVA',
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#4f46e5',
            'theme_color' => '#4f46e5',
            'icons' => [['src' => '/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }
}
