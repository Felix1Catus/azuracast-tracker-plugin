<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Console;

use App\Console\Command\CommandAbstract;
use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use Plugin\BranchTracker\Service\StationSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'branch-tracker:public-page',
    description: 'Serve the branch PIN login + player on the station\'s own public page: on | off (omit to show).',
)]
final class PublicPageCommand extends CommandAbstract
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationSettingsService $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station', InputArgument::REQUIRED, 'Station short name or id')
            ->addArgument('state', InputArgument::OPTIONAL, 'on | off');
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

        $state = $input->getArgument('state');
        if (null !== $state) {
            if (!in_array($state, ['on', 'off'], true)) {
                $io->error('State must be on or off.');
                return 1;
            }
            $this->settings->setPublicPageLogin($station, 'on' === $state);
        }

        $s = $this->settings->toArray($station);
        $io->writeln(sprintf(
            '/public/%s: %s%s',
            $station->short_name,
            $s['public_page_login_effective'] ? 'branch PIN login (Tracker player)' : 'stock AzuraCast page',
            (!$s['public_page_login'] && $s['public_page_login_effective']) ? ' [forced by stream-auth enforce]' : ''
        ));
        return 0;
    }
}
