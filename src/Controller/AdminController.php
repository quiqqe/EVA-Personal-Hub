<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppError;
use App\Entity\SecurityEvent;
use App\Entity\User;
use App\Monitoring\Alerts;
use App\Telegram;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Administration (ROLE_ADMIN; ROLE_SUPER_ADMIN also gives and takes roles):
 * overview, errors of the site, the security journal of everyone, users.
 */
#[Route('/admin')]
final class AdminController extends BaseController
{
    private const PAGE = 50;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'admin')]
    public function index(Telegram $telegram, #[Autowire(env: 'default::TELEGRAM_ADMIN_CHAT_ID')] ?string $adminChat, #[Autowire(env: 'default::SENTRY_DSN')] ?string $sentryDsn): Response
    {
        $db = $this->em->getConnection();
        $admins = $this->em->getRepository(User::class)->findBy(['role' => array_keys(User::ROLES)]);

        return $this->render('admin/index.html.twig', [
            'tab' => 'overview',
            'state' => [
                'bot' => $telegram->enabled(),
                'alertsTo' => $adminChat ? 'chat' : (array_filter($admins, fn (User $u) => $u->telegramId) ? 'admins' : null),
                'admins' => $admins,
                'sentry' => (bool) $sentryDsn,
                'env' => $this->getParameter('kernel.environment'),
            ],
            'day' => [
                'errors' => (int) $db->fetchOne("SELECT COALESCE(SUM(count), 0) FROM app_error WHERE last_seen > NOW() - INTERVAL '24 hours'"),
                'open' => (int) $db->fetchOne('SELECT COUNT(*) FROM app_error WHERE NOT resolved'),
                'failed' => (int) $db->fetchOne("SELECT COUNT(*) FROM security_event WHERE type IN ('login_failed', 'rate_limited') AND created_at > NOW() - INTERVAL '24 hours'"),
                'logins' => (int) $db->fetchOne("SELECT COUNT(*) FROM security_event WHERE type = 'login' AND created_at > NOW() - INTERVAL '24 hours'"),
                'server' => (int) $db->fetchOne("SELECT COUNT(*) FROM security_event WHERE type = 'server' AND created_at > NOW() - INTERVAL '24 hours'"),
            ],
        ]);
    }

    #[Route('/errors', name: 'admin_errors')]
    public function errors(Request $r): Response
    {
        $all = $r->query->getBoolean('all');

        return $this->render('admin/errors.html.twig', [
            'tab' => 'errors',
            'all' => $all,
            'errors' => $this->em->getRepository(AppError::class)->findBy($all ? [] : ['resolved' => false], ['lastSeen' => 'DESC'], 200),
        ]);
    }

    #[Route('/errors/{hash}', name: 'admin_error', requirements: ['hash' => '[0-9a-f]{32}'])]
    public function error(Request $r, string $hash): Response
    {
        $e = $this->em->find(AppError::class, $hash) ?? throw $this->createNotFoundException();
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $e->resolved = !$e->resolved;
            $this->em->flush();

            return $this->redirectToRoute('admin_errors');
        }

        return $this->render('admin/error.html.twig', ['tab' => 'errors', 'e' => $e]);
    }

    #[Route('/security', name: 'admin_security')]
    public function security(Request $r): Response
    {
        $type = (string) $r->query->get('type');
        $q = trim((string) $r->query->get('q'));
        $page = max(1, $r->query->getInt('page', 1));
        $qb = $this->em->getRepository(SecurityEvent::class)->createQueryBuilder('e')->leftJoin('e.user', 'u')->orderBy('e.createdAt', 'DESC');
        if ($type === 'bad') {
            $qb->andWhere("e.type IN ('login_failed', 'rate_limited')");
        } elseif (isset(SecurityEvent::TYPES[$type])) {
            $qb->andWhere('e.type = :t')->setParameter('t', $type);
        }
        if ($q !== '') {
            // by IP, e-mail tried, name or anything in the detail
            $qb->andWhere('e.ip LIKE :q OR LOWER(e.detail) LIKE :q OR LOWER(u.name) LIKE :q OR LOWER(u.email) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(addcslashes($q, '%_')).'%');
        }
        $events = $qb->setFirstResult(($page - 1) * self::PAGE)->setMaxResults(self::PAGE + 1)->getQuery()->getResult();

        return $this->render('admin/security.html.twig', [
            'tab' => 'security',
            'events' => \array_slice($events, 0, self::PAGE),
            'more' => \count($events) > self::PAGE,
            'page' => $page,
            'type' => $type,
            'q' => $q,
            'types' => SecurityEvent::TYPES,
        ]);
    }

    #[Route('/users', name: 'admin_users')]
    public function users(): Response
    {
        $db = $this->em->getConnection();

        return $this->render('admin/users.html.twig', [
            'tab' => 'users',
            'counts' => $db->fetchAssociative("SELECT COUNT(*) total, COUNT(*) FILTER (WHERE created_at > NOW() - INTERVAL '7 days') week,
                COUNT(*) FILTER (WHERE telegram_id IS NOT NULL) tg, COUNT(*) FILTER (WHERE email IS NOT NULL) email FROM users"),
            'users' => $db->fetchAllAssociative("SELECT u.id, u.name, u.email, u.telegram_id IS NOT NULL tg, u.role, u.created_at,
                (SELECT MAX(e.created_at) FROM security_event e WHERE e.user_id = u.id AND e.type = 'login') last_login,
                (SELECT COUNT(*) FROM security_event e WHERE e.user_id = u.id AND e.type = 'login_failed' AND e.created_at > NOW() - INTERVAL '7 days') failed
                FROM users u ORDER BY u.created_at DESC"),
            'roles' => User::ROLES,
        ]);
    }

    /** Only a super admin gives and takes roles; nobody can lower their own (no locking yourself out). */
    #[Route('/users/{id}/role', name: 'admin_user_role', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function role(Request $r, string $id): Response
    {
        $this->checkCsrf($r);
        $user = Uuid::isValid($id) ? $this->em->find(User::class, $id) : null;
        $role = (string) $r->request->get('role');
        if (!$user || ($role !== '' && !isset(User::ROLES[$role]))) {
            throw $this->createNotFoundException();
        }
        if ($user === $this->me()) {
            $this->addFlash('error', 'Свою роль менять нельзя — попросите другого суперадмина или используйте консоль');
        } else {
            $user->role = $role ?: null;
            $this->em->flush();
            $this->securityLog('role_changed', $user, ($role ? User::ROLES[$role] : 'обычный пользователь').' · назначил '.$this->me()->name);
            $this->addFlash('ok', $user->name.': '.($role ? User::ROLES[$role] : 'обычный пользователь').'. Применится при следующем входе.');
        }

        return $this->redirectToRoute('admin_users');
    }

    /** "Does it reach me?" — a test alert, and a test error (lands in «Ошибки» and in Bugsink). */
    #[Route('/test', name: 'admin_test', methods: ['POST'])]
    public function test(Request $r, Alerts $alerts, \Psr\Log\LoggerInterface $logger): Response
    {
        $this->checkCsrf($r);
        if ($r->request->has('error')) {
            $logger->error('Тестовая ошибка из админки', ['exception' => new \RuntimeException('Тестовая ошибка из админки EVA')]);
            \Sentry\captureMessage('Проверка Bugsink из админки EVA');
            $this->addFlash('ok', 'Тестовая ошибка записана — смотрите «Ошибки» (и Bugsink, если подключён)');
        } else {
            $sent = $alerts->send('✅ Проверка уведомлений из админки EVA');
            $this->addFlash($sent ? 'ok' : 'error', $sent ? 'Тестовое уведомление отправлено' : 'Не отправлено: нет чата уведомлений и нет администратора с привязанным Telegram');
        }

        return $this->redirectToRoute('admin');
    }
}
