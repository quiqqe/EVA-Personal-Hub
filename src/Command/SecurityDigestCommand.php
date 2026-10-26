<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SecurityEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * EVA's part of the server's daily security summary: sign-ins, failures, who was hammering. Plain text.
 * Events the server's scripts wrote here (type "server": SSH, bans, health) are left out — the summary has them first-hand.
 */
#[AsCommand('app:security:digest', 'Summary of the security journal for the last N hours')]
final class SecurityDigestCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Period', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = (new \DateTimeImmutable('-'.max(1, (int) $input->getOption('hours')).' hours'))->format('Y-m-d H:i:sP');
        $counts = $this->db->fetchAllKeyValue('SELECT type, COUNT(*) FROM security_event WHERE created_at >= ? AND type <> ? GROUP BY type ORDER BY 2 DESC', [$since, 'server']);
        $bad = "type IN ('login_failed', 'rate_limited')";
        $ips = $this->db->fetchAllKeyValue("SELECT ip, COUNT(*) FROM security_event WHERE created_at >= ? AND $bad GROUP BY ip ORDER BY 2 DESC LIMIT 5", [$since]);
        $targets = $this->db->fetchAllKeyValue("SELECT detail, COUNT(*) FROM security_event WHERE created_at >= ? AND type = 'login_failed' AND detail IS NOT NULL GROUP BY detail ORDER BY 2 DESC LIMIT 5", [$since]);

        $line = fn (array $kv) => implode(', ', array_map(fn ($k, $v) => "$k ×$v", array_keys($kv), $kv));
        $output->writeln('EVA: '.($counts ? $line(array_combine(array_map(fn ($t) => SecurityEvent::TYPES[$t] ?? $t, array_keys($counts)), $counts)) : 'событий нет'));
        $ips && $output->writeln('  неудачные входы с IP: '.$line($ips));
        $targets && $output->writeln('  пытались войти как: '.$line($targets));

        return Command::SUCCESS;
    }
}
