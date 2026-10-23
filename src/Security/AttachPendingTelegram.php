<?php

declare(strict_types=1);

namespace App\Security;

use App\Controller\TelegramController;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** "I already have an account": a Telegram confirmed just before an e-mail sign-in gets attached to that account. */
#[AsEventListener]
final readonly class AttachPendingTelegram
{
    public function __construct(private UserRepository $users, private EntityManagerInterface $em, private SecurityLog $log)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
        $pending = $session?->get(TelegramController::PENDING);
        $user = $event->getUser();
        if (!\is_array($pending) || !$user instanceof User) {
            return;
        }
        $session->remove(TelegramController::PENDING);
        if ($user->telegramId === null && !$this->users->findOneBy(['telegramId' => $pending['id']])) {
            $user->telegramId = (int) $pending['id'];
            $this->em->flush();
            $this->log->record('telegram_linked', $user, 'при входе по email');
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('ok', 'Telegram привязан к вашему аккаунту — дальше можно входить в один тап');
            }
        }
    }
}
