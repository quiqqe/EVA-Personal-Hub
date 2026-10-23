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

/** The only way to become an administrator: from the server console, never from the site. */
#[AsCommand('app:admin', 'Grant or revoke site administrator; without arguments — list administrators')]
final class AdminCommand extends Command
{
    public function __construct(private readonly UserRepository $users, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('who', InputArgument::OPTIONAL, 'E-mail or tg:<Telegram id>')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Take the role away');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $who = trim((string) $input->getArgument('who'));
        if ($who === '') {
            foreach ($this->users->findBy(['admin' => true]) as $a) {
                $output->writeln($a->getUserIdentifier().' — '.$a->name.($a->telegramId ? ', Telegram есть' : ', без Telegram: уведомления не получит'));
            }

            return Command::SUCCESS;
        }
        $user = str_starts_with($who, 'tg:')
            ? $this->users->findOneBy(['telegramId' => (int) substr($who, 3)])
            : $this->users->findOneBy(['email' => mb_strtolower($who)]);
        if (!$user instanceof User) {
            $output->writeln("<error>Нет пользователя $who</error>");

            return Command::FAILURE;
        }
        $user->admin = !$input->getOption('revoke');
        $this->em->flush();
        $output->writeln($user->name.($user->admin ? ' — теперь администратор' : ' — больше не администратор'));

        return Command::SUCCESS;
    }
}
