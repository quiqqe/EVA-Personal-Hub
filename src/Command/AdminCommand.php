<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Roles from the server console: the first super admin is made here, later ones — from the admin page too. */
#[AsCommand('app:admin', 'Give or take the admin / super_admin role; without arguments — list who has one')]
final class AdminCommand extends Command
{
    public function __construct(private readonly UserRepository $users, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('who', InputArgument::OPTIONAL, 'E-mail, tg:<Telegram id> or name:<имя> (if the name is unique)')
            ->addOption('super', null, InputOption::VALUE_NONE, 'super_admin instead of admin')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Take the role away');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $who = trim((string) $input->getArgument('who'));
        if ($who === '') {
            foreach ($this->users->findBy(['role' => array_keys(User::ROLES)]) as $a) {
                $output->writeln(User::ROLES[$a->role].': '.$a->name.' ('.$a->getUserIdentifier().')'.($a->telegramId ? '' : ' — без Telegram, уведомления не получит'));
            }

            return Command::SUCCESS;
        }
        $found = match (true) {
            str_starts_with($who, 'tg:') => $this->users->findBy(['telegramId' => (int) substr($who, 3)]),
            str_starts_with($who, 'name:') => $this->users->findBy(['name' => trim(substr($who, 5))]),
            default => $this->users->findBy(['email' => mb_strtolower($who)]),
        };
        if (\count($found) !== 1) {
            $output->writeln(\count($found) ? "<error>Под «{$who}» несколько пользователей — укажите email или tg:id</error>" : "<error>Нет пользователя {$who}</error>");
            foreach ($found as $u) {
                $output->writeln('  '.$u->getUserIdentifier().' — '.$u->name);
            }

            return Command::FAILURE;
        }
        $user = $found[0];
        $user->role = $input->getOption('revoke') ? null : ($input->getOption('super') ? 'super_admin' : 'admin');
        $this->em->flush();
        $output->writeln($user->name.': '.($user->role ? User::ROLES[$user->role] : 'обычный пользователь').'. Чтобы роль применилась — войти заново.');

        return Command::SUCCESS;
    }
}
