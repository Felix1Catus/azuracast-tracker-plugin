<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Console;

use App\Console\Command\CommandAbstract;
use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use DateTimeImmutable;
use DateTimeZone;
use Plugin\BranchTracker\Service\PresenceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the presence sync now (it also runs every minute via azuracast:sync:run) and prints live status.
 * --now lets tests simulate a later time, e.g. the 00:30 reset; times are in the station's timezone.
 */
#[AsCommand(
    name: 'branch-tracker:presence',
    description: 'Run the branch presence sync now (link streams, timeouts, daily reset) and show live status.',
)]
final class PresenceCommand extends CommandAbstract
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly PresenceService $presence,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station', InputArgument::REQUIRED, 'Station short name or id')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Pretend it is this station-local time (testing)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print live status as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $arg = (string)$input->getArgument('station');
        $station = $this->em->getRepository(Station::class)->findOneBy(
            ctype_digit($arg) ? ['id' => (int)$arg] : ['short_name' => $arg]
        );
        if (!$station instanceof Station) {
            $io->error("Station \"$arg\" not found.");
            return 1;
        }

        $tz = new DateTimeZone($station->timezone ?: 'UTC');
        $now = null === $input->getOption('now')
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : (new DateTimeImmutable((string)$input->getOption('now'), $tz))->setTimezone(new DateTimeZone('UTC'));

        $result = $this->presence->sync($station, $now);
        $live = $this->presence->live($station, $now);

        if ($input->getOption('json')) {
            $output->writeln(json_encode(['sync' => $result, 'branches' => $live], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return 0;
        }

        $io->writeln(sprintf(
            'Sync at %s: linked %d, timed out %d, daily reset %d',
            $now->setTimezone($tz)->format('Y-m-d H:i:s T'),
            $result['linked'],
            $result['timed_out'],
            $result['daily_reset']
        ));
        $rows = array_map(static fn(array $b) => [
            $b['code'], $b['name'], $b['status'] . ($b['status_source'] ? " ({$b['status_source']})" : ''),
            gmdate('G:i:s', $b['listened_today_seconds']), $b['session']['volume'] ?? '',
        ], array_filter($live, static fn(array $b) => 'offline' !== $b['status']));
        $io->table(['Code', 'Branch', 'Status', 'Today', 'Vol'], $rows);
        $io->writeln(count($live) - count($rows) . ' branch(es) offline.');
        return 0;
    }
}
