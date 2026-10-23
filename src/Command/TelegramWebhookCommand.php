<?php

declare(strict_types=1);

namespace App\Command;

use App\Telegram;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Points the bot at this site (run by bin/deploy; safe to repeat). */
#[AsCommand('app:telegram:webhook', 'Register the Telegram webhook for the bot')]
final class TelegramWebhookCommand extends Command
{
    public function __construct(private readonly Telegram $telegram, private readonly UrlGeneratorInterface $urls)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->telegram->enabled()) {
            $output->writeln('Telegram bot is not configured, skipped');

            return Command::SUCCESS;
        }
        $url = $this->urls->generate('telegram_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL);
        if (!str_starts_with($url, 'https://')) {
            $output->writeln("<error>Webhook needs https, got $url — set DEFAULT_URI</error>");

            return Command::FAILURE;
        }
        $result = $this->telegram->call('setWebhook', [
            'url' => $url,
            'secret_token' => $this->telegram->webhookSecret(),
            'allowed_updates' => ['message', 'callback_query'],
        ]);
        $output->writeln(($result['ok'] ?? false) ? "Webhook: $url" : '<error>setWebhook failed: '.($result['description'] ?? 'no answer').'</error>');
        // the bot's menu button opens the site itself as a Mini App: nothing to update in Telegram when the site changes
        $app = $this->urls->generate('telegram_app', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $menu = $this->telegram->call('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => 'Открыть', 'web_app' => ['url' => $app]]]);
        $output->writeln(($menu['ok'] ?? false) ? "Menu button: $app" : '<error>setChatMenuButton failed: '.($menu['description'] ?? 'no answer').'</error>');

        return ($result['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
    }
}
