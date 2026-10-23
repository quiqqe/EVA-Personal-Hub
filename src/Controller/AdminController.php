<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SecurityEvent;
use App\Entity\User;
use App\Monitoring\Alerts;
use App\Telegram;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** For site administrators only (ROLE_ADMIN, granted with app:admin): system state, users, security journal. */
#[Route('/admin')]
final class AdminController extends BaseController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'admin')]
    public function index(Request $r, Telegram $telegram, #[Autowire(env: 'default::TELEGRAM_ADMIN_CHAT_ID')] ?string $adminChat, #[Autowire(env: 'default::SENTRY_DSN')] ?string $sentryDsn): Response
    {
        $db = $this->em->getConnection();
        $admins = $this->em->getRepository(User::class)->findBy(['admin' => true]);
        $failedOnly = $r->query->getBoolean('failed');

        return $this->render('admin/index.html.twig', [
            'state' => [
                'bot' => $telegram->enabled(),
                'alertsTo' => $adminChat ? 'chat' : (array_filter($admins, fn (User $u) => $u->telegramId) ? 'admins' : null),
                'admins' => $admins,
                'sentry' => (bool) $sentryDsn,
                'env' => $this->getParameter('kernel.environment'),
            ],
            'counts' => $db->fetchAssociative("SELECT COUNT(*) total, COUNT(*) FILTER (WHERE created_at > NOW() - INTERVAL '7 days') week,
                COUNT(*) FILTER (WHERE telegram_id IS NOT NULL) tg, COUNT(*) FILTER (WHERE email IS NOT NULL) email FROM users"),
            // each user with their last sign-in from the journal
            'users' => $db->fetchAllAssociative("SELECT u.id, u.name, u.email, u.telegram_id IS NOT NULL tg, u.admin, u.created_at,
                (SELECT MAX(e.created_at) FROM security_event e WHERE e.user_id = u.id AND e.type = 'login') last_login,
                (SELECT COUNT(*) FROM security_event e WHERE e.user_id = u.id AND e.type = 'login_failed' AND e.created_at > NOW() - INTERVAL '7 days') failed
                FROM users u ORDER BY u.created_at DESC"),
            'events' => $this->em->getRepository(SecurityEvent::class)->findBy($failedOnly ? ['type' => ['login_failed', 'rate_limited']] : [], ['createdAt' => 'DESC'], 100),
            'failedOnly' => $failedOnly,
        ]);
    }

    /** "Does it reach me?" — a test alert, and a test error for Bugsink. */
    #[Route('/test', name: 'admin_test', methods: ['POST'])]
    public function test(Request $r, Alerts $alerts): Response
    {
        $this->checkCsrf($r);
        if ($r->request->has('error')) {
            \Sentry\captureMessage('Проверка Bugsink из админки EVA');
            $this->addFlash('ok', 'Тестовое событие отправлено в Bugsink (если SENTRY_DSN задан)');
        } else {
            $sent = $alerts->send('✅ Проверка уведомлений из админки EVA');
            $this->addFlash($sent ? 'ok' : 'error', $sent ? 'Тестовое уведомление отправлено' : 'Не отправлено: нет чата уведомлений и нет администратора с привязанным Telegram');
        }

        return $this->redirectToRoute('admin');
    }
}
