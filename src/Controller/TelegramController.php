<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TelegramLogin;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Money\Money;
use App\Telegram;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Sign in / sign up / link through the bot: the site opens the Telegram app on the bot with a one-time code,
 * the person presses Start and confirms, the browser that asked (it polls) gets signed in.
 */
final class TelegramController extends BaseController
{
    private const SESSION = 'telegram_login';
    /** a confirmed Telegram waiting to become an account or to be attached at the next e-mail sign-in */
    public const PENDING = 'telegram_pending';

    public function __construct(
        private readonly Telegram $telegram,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/login/telegram', name: 'login_telegram', methods: ['GET'])]
    public function login(Request $r): Response
    {
        return $this->start($r, null);
    }

    #[Route('/profile/telegram', name: 'profile_telegram_link', methods: ['GET'])]
    public function link(Request $r): Response
    {
        return $this->start($r, $this->me());
    }

    private function start(Request $r, ?User $linkTo): Response
    {
        if (!$this->telegram->enabled()) {
            throw $this->createNotFoundException();
        }
        $this->em->createQuery('DELETE FROM '.TelegramLogin::class.' t WHERE t.createdAt < :old')
            ->setParameter('old', new \DateTimeImmutable('-'.TelegramLogin::TTL.' seconds'))->execute();
        $attempt = new TelegramLogin($linkTo, self::describe($r));
        $this->em->persist($attempt);
        $this->em->flush();
        // only this browser may finish the sign-in, whoever presses the button in Telegram
        $r->getSession()->set(self::SESSION, $attempt->code);

        $bot = $this->telegram->botUsername;

        return $this->render('auth/telegram_wait.html.twig', [
            'web' => "https://t.me/$bot?start={$attempt->code}",
            'appLink' => "tg://resolve?domain=$bot&start={$attempt->code}",
            'linking' => $linkTo !== null,
        ]);
    }

    #[Route('/login/telegram/check', name: 'login_telegram_check', methods: ['GET'])]
    public function check(Request $r, Security $security): JsonResponse
    {
        $code = (string) $r->getSession()->get(self::SESSION);
        $attempt = $code !== '' ? $this->em->find(TelegramLogin::class, $code) : null;
        if (!$attempt || $attempt->expired()) {
            return $this->json(['status' => 'expired']);
        }
        if (!$attempt->confirmedAt || $attempt->telegramId === null) {
            return $this->json(['status' => 'pending']);
        }
        $r->getSession()->remove(self::SESSION);
        $this->em->remove($attempt);
        $owner = $this->users->findOneBy(['telegramId' => $attempt->telegramId]);

        if ($attempt->linkTo) {
            if ($this->getUser() !== $attempt->linkTo) {
                $this->em->flush();

                return $this->json(['status' => 'expired']);
            }
            if ($owner && $owner !== $attempt->linkTo) {
                $this->addFlash('error', 'Этот Telegram уже привязан к другому аккаунту');
            } else {
                $attempt->linkTo->telegramId = $attempt->telegramId;
                $this->addFlash('ok', 'Telegram привязан — теперь можно входить в один тап');
            }
            $this->em->flush();

            return $this->json(['status' => 'ok', 'redirect' => $this->generateUrl('profile')]);
        }

        $this->em->flush();
        if (!$owner) {
            // no account with this Telegram: ask whether to create one or attach it to an existing e-mail account
            $r->getSession()->set(self::PENDING, ['id' => $attempt->telegramId, 'name' => $attempt->telegramName ?? 'Telegram']);

            return $this->json(['status' => 'ok', 'redirect' => $this->generateUrl('login_telegram_new')]);
        }
        $next = $this->afterLogin($r);
        $security->login($owner, 'form_login', 'main', [(new RememberMeBadge())->enable()]);

        return $this->json(['status' => 'ok', 'redirect' => $next]);
    }

    /** Telegram confirmed, but no account knows it yet: a new account, or sign in by e-mail and attach it. */
    #[Route('/login/telegram/new', name: 'login_telegram_new', methods: ['GET', 'POST'])]
    public function newAccount(Request $r, Security $security): Response
    {
        $pending = $r->getSession()->get(self::PENDING);
        if (!\is_array($pending) || $this->users->findOneBy(['telegramId' => $pending['id']])) {
            $r->getSession()->remove(self::PENDING);

            return $this->redirectToRoute('login');
        }
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $currency = (string) $r->request->get('currency');
            $user = new User(null, $pending['name'], isset(Money::choices()[$currency]) ? $currency : 'RUB');
            $user->telegramId = (int) $pending['id'];
            $this->em->persist($user);
            $this->em->flush();
            $r->getSession()->remove(self::PENDING);
            $next = $this->afterLogin($r);
            $security->login($user, 'form_login', 'main', [(new RememberMeBadge())->enable()]);

            return $this->redirect($next);
        }

        return $this->render('auth/telegram_new.html.twig', ['name' => $pending['name'], 'invitedTo' => $this->invitedTo($r)]);
    }

    /** Updates from Telegram. Always 200: Telegram retries anything else. */
    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
    public function webhook(Request $r): Response
    {
        if (!$this->telegram->enabled() || !hash_equals($this->telegram->webhookSecret(), (string) $r->headers->get('X-Telegram-Bot-Api-Secret-Token'))) {
            return new Response('', 403);
        }
        try {
            $u = Telegram::parse(json_decode($r->getContent(), true) ?: []);
            if ($u) {
                $u['kind'] === 'start' ? $this->onStart($u) : $this->onButton($u);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Telegram update failed: '.$e->getMessage());
        }

        return new Response('');
    }

    private function onStart(array $u): void
    {
        $site = $this->generateUrl('hub', [], 0);
        $attempt = $u['param'] !== '' ? $this->em->find(TelegramLogin::class, $u['param']) : null;
        if (!$attempt) {
            $this->telegram->call('sendMessage', ['chat_id' => $u['chatId'], 'text' => $u['param'] === ''
                ? "Это бот EVA Personal.\nВойти на сайт: $site"
                : "Ссылка для входа устарела. Нажмите «Войти через Telegram» на сайте ещё раз:\n$site"]);

            return;
        }
        // the code belongs to the Telegram account that pressed Start first; anyone else forwarding it gets nothing
        if ($attempt->expired() || $attempt->confirmedAt || ($attempt->telegramId !== null && $attempt->telegramId !== $u['tgId'])) {
            $this->telegram->call('sendMessage', ['chat_id' => $u['chatId'], 'text' => "Ссылка для входа устарела. Нажмите «Войти через Telegram» на сайте ещё раз:\n$site"]);

            return;
        }
        $attempt->telegramId = $u['tgId'];
        $attempt->telegramName = $u['name'];
        $this->em->flush();

        $what = $attempt->linkTo
            ? 'Привязать этот Telegram к аккаунту «'.$attempt->linkTo->name.'» в EVA Personal?'
            : 'Войти в EVA Personal?';
        $this->telegram->call('sendMessage', [
            'chat_id' => $u['chatId'],
            'text' => "$what\n\nЗапрос: {$attempt->requestedFrom}\nВремя: ".$attempt->createdAt->format('d.m.Y H:i')." UTC\n\n"
                ."Подтверждайте, только если вы сами только что нажали кнопку на сайте. Если нет — просто проигнорируйте.",
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => '✅ Да, это я', 'callback_data' => 'ok:'.$attempt->code],
                ['text' => 'Отмена', 'callback_data' => 'no:'.$attempt->code],
            ]]],
        ]);
    }

    private function onButton(array $u): void
    {
        [$action, $code] = explode(':', $u['param'], 2) + [1 => ''];
        $attempt = $code !== '' ? $this->em->find(TelegramLogin::class, $code) : null;
        // the confirmation counts only from the same Telegram account that pressed Start
        $valid = $attempt && !$attempt->expired() && !$attempt->confirmedAt && $attempt->telegramId === $u['tgId'];
        if ($valid && $action === 'ok') {
            $attempt->confirmedAt = new \DateTimeImmutable();
            $text = '✅ Подтверждено. Вернитесь в браузер — вход произойдёт сам.';
        } elseif ($valid && $action === 'no') {
            $this->em->remove($attempt);
            $text = 'Отменено. Если это были не вы — ничего не случилось, доступ никто не получил.';
        } else {
            $text = 'Ссылка устарела. Начните вход на сайте ещё раз.';
        }
        $this->em->flush();
        $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $u['callbackId']]);
        $this->telegram->call('editMessageText', ['chat_id' => $u['chatId'], 'message_id' => $u['messageId'], 'text' => $text]);
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

    /** "Chrome, macOS · 1.2.3.4": so the person recognises their own request in the bot. */
    public static function describe(Request $r): string
    {
        $ua = (string) $r->headers->get('User-Agent');
        $browser = match (true) {
            str_contains($ua, 'YaBrowser') => 'Яндекс Браузер',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'браузер',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return mb_substr($browser.($os ? ", $os" : '').' · IP '.$r->getClientIp(), 0, 200);
    }
}
