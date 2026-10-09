<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Console;

use App\Console\Command\CommandAbstract;
use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use App\Entity\StationMount;
use App\Environment;
use App\Radio\Configuration;
use Plugin\BranchTracker\StreamAuthMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns branch stream auth on/off for every mount of a station, via AzuraCast's stock per-mount
 * custom frontend config (merged into icecast.xml), then rewrites config and restarts the station.
 * Restarting briefly drops all listeners (players reconnect on their own).
 */
#[AsCommand(
    name: 'branch-tracker:stream-auth',
    description: 'Set branch stream auth for a station: off | observe | enforce.',
)]
final class StreamAuthCommand extends CommandAbstract
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly Environment $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station', InputArgument::REQUIRED, 'Station short name or id')
            ->addArgument('mode', InputArgument::OPTIONAL, 'off | observe | enforce (omit to show current)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite non-JSON custom mount config');
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

        $modeArg = $input->getArgument('mode');
        if (null === $modeArg) {
            foreach ($station->mounts as $mount) {
                $io->writeln(sprintf('%s: %s', $mount->name, $this->currentMode($mount)));
            }
            return 0;
        }
        $mode = StreamAuthMode::tryFrom((string)$modeArg);
        if (null === $mode) {
            $io->error('Mode must be off, observe or enforce.');
            return 1;
        }

        /** @var StationMount $mount */
        foreach ($station->mounts as $mount) {
            $raw = trim($mount->frontend_config ?? '');
            $config = '' === $raw ? [] : json_decode($raw, true);
            if (!is_array($config)) {
                if (!$input->getOption('force')) {
                    $io->error("Mount {$mount->name} has non-JSON custom config; re-run with --force to replace it.");
                    return 1;
                }
                $config = [];
            }

            unset($config['authentication']);
            if (StreamAuthMode::Off !== $mode) {
                $config['authentication'] = [[
                    '@type' => 'url',
                    'option' => [
                        ['@name' => 'listener_add', '@value' => $this->authUrl($station, $mode)],
                        ['@name' => 'auth_header', '@value' => 'icecast-auth-user: 1'],
                    ],
                ]];
            }

            $mount->frontend_config = [] === $config ? null : json_encode($config, JSON_UNESCAPED_SLASHES);
            $this->em->persist($mount);
            $io->writeln(sprintf('%s: %s', $mount->name, $mode->value));
        }
        $this->em->flush();

        $io->writeln('Rewriting configuration and restarting the station...');
        $this->configuration->writeConfiguration($station, forceRestart: true);
        $io->success(sprintf('Stream auth for "%s" is now %s.', $station->short_name, $mode->value));
        return 0;
    }

    private function authUrl(Station $station, StreamAuthMode $mode): string
    {
        // Icecast calls back into AzuraCast through its internal nginx (http://127.0.0.1:6010 in Docker).
        return sprintf(
            '%s/api/branch-tracker/internal/%d/listener-auth/%s/%s',
            rtrim((string)$this->environment->getInternalUri(), '/'),
            $station->id,
            $mode->value,
            $station->adapter_api_key
        );
    }

    private function currentMode(StationMount $mount): string
    {
        $config = json_decode(trim($mount->frontend_config ?? ''), true);
        $url = $config['authentication'][0]['option'][0]['@value'] ?? '';
        return preg_match('#/listener-auth/(observe|enforce)/#', (string)$url, $m) ? $m[1] : 'off';
    }
}
