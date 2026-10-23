<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitoring\Alerts;
use App\Security\SecurityLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Server scripts report here (an SSH sign-in, a full disk, a ban…): the event lands in the security journal
 * (admin → «Безопасность») and, with --alert, also in the administrators' Telegram.
 */
#[AsCommand('app:server-event', 'Record a server event in the security journal (and optionally alert)')]
final class ServerEventCommand extends Command
{
    public function __construct(private readonly SecurityLog $log, private readonly Alerts $alerts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('text', InputArgument::REQUIRED, 'What happened')
            ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'IP involved, if any')
            ->addOption('alert', null, InputOption::VALUE_NONE, 'Also send to Telegram');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $text = (string) $input->getArgument('text');
        $this->log->server($text, (string) $input->getOption('ip'));
        if ($input->getOption('alert')) {
            $this->alerts->send($text);
        }

        return Command::SUCCESS;
    }
}
