<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitoring\Alerts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Sends a message to the admin chat; server scripts use it so the bot token stays inside the app. */
#[AsCommand('app:alert', 'Send a message to the admin Telegram chat')]
final class AlertCommand extends Command
{
    public function __construct(private readonly Alerts $alerts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('text', InputArgument::REQUIRED, 'Message text ("-" reads it from stdin)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $text = (string) $input->getArgument('text');
        if ($text === '-') {
            $text = (string) stream_get_contents(STDIN);
        }
        if ($this->alerts->send(trim($text))) {
            return Command::SUCCESS;
        }
        $output->writeln('<error>Not sent: no TELEGRAM_ADMIN_CHAT_ID and no administrator with Telegram (app:admin), or the bot is not configured</error>');

        return Command::FAILURE;
    }
}
