<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Money\Ledger;
use App\Money\Money;
use App\Money\Rates;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
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
        // the hub's total must already include today's salary or rent
        $ledger->open(new Scope($me));
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

    /** "How it works": the user guide itself, so the app and the docs never disagree. */
    #[Route('/help', name: 'help')]
    public function help(): Response
    {
        $md = (string) file_get_contents($this->getParameter('kernel.project_dir').'/docs/user-guide.md');
        $html = (new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]))->convert($md);

        return $this->render('help.html.twig', ['guide' => (string) $html]);
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
            if (isset(Money::choices()[$currency]) && $currency !== $me->currency) {
                $me->currency = $currency;
                // totals switch to the new base right away
                $r->getSession()->remove('money_in_me');
            }
            $tz = (string) $r->request->get('timezone');
            if ($tz === 'auto') {
                $me->timezoneAuto = true;
            } elseif (\in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $me->timezone = $tz;
                $me->timezoneAuto = false;
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
                // a reset link sent earlier must not undo the new password
                $em->createQuery('DELETE FROM '.\App\Entity\PasswordReset::class.' p WHERE p.user = :u')->setParameter('u', $me)->execute();
            }
            $em->flush();
            $this->addFlash('ok', 'Сохранено');

            return $this->redirectToRoute('profile');
        }

        return $this->render('profile.html.twig');
    }

    /** The browser reports its zone; it's taken unless the person chose one in the profile. */
    #[Route('/profile/timezone', name: 'profile_timezone', methods: ['POST'])]
    public function timezone(Request $r, EntityManagerInterface $em): Response
    {
        $this->checkCsrf($r);
        $tz = (string) $r->request->get('timezone');
        $me = $this->me();
        if ($me->timezoneAuto && \in_array($tz, \DateTimeZone::listIdentifiers(), true) && $tz !== $me->timezone) {
            $me->timezone = $tz;
            $em->flush();
        }

        return new Response('', 204);
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
            'icons' => [
                ['src' => '/icon.svg?v=2', 'sizes' => 'any', 'type' => 'image/svg+xml'],
                ['src' => '/icon-192.png?v=2', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icon-512.png?v=2', 'sizes' => '512x512', 'type' => 'image/png'],
                // full-bleed, the system crops it to its own shape
                ['src' => '/icon-maskable-512.png?v=2', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }
}
