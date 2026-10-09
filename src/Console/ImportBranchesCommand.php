<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Console;

use App\Console\Command\CommandAbstract;
use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use Plugin\BranchTracker\Exception\ValidationException;
use Plugin\BranchTracker\Service\BranchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports branches from a CSV, e.g. the Google Sheet "Branches" tab exported as CSV
 * (columns "Pins", "Branch Name", "Branch ID"; other headers containing pin / name / id|code work too).
 * Upserts by branch code. CSV PINs must have the configured length (default 4), unless --legacy-pins accepts the old 4-8 digit ones
 * (for a pilot alongside the Sheet). --new-pins ignores the CSV PINs, generates fresh ones and
 * prints them (the only time they are visible).
 */
#[AsCommand(
    name: 'branch-tracker:import',
    description: 'Import or update branches from a CSV (Pins, Branch Name, Branch ID).',
)]
final class ImportBranchesCommand extends CommandAbstract
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly BranchService $branches,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station', InputArgument::REQUIRED, 'Station short name or id, e.g. "store"')
            ->addArgument('csv', InputArgument::REQUIRED, 'Path to the CSV file (inside the container)')
            ->addOption('new-pins', null, InputOption::VALUE_NONE, 'Ignore CSV PINs and generate new ones')
            ->addOption('legacy-pins', null, InputOption::VALUE_NONE, 'Accept existing 4-8 digit PINs from the CSV (until rotated)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stationArg = (string)$input->getArgument('station');
        $station = $this->em->getRepository(Station::class)->findOneBy(
            ctype_digit($stationArg) ? ['id' => (int)$stationArg] : ['short_name' => $stationArg]
        );
        if (!$station instanceof Station) {
            $io->error("Station \"$stationArg\" not found.");
            return 1;
        }

        $rows = $this->readCsv((string)$input->getArgument('csv'), $io);
        if (null === $rows) {
            return 1;
        }

        $newPins = (bool)$input->getOption('new-pins');
        $dryRun = (bool)$input->getOption('dry-run');
        $legacyPins = (bool)$input->getOption('legacy-pins');
        $report = [];
        $errors = 0;

        $this->em->beginTransaction();
        try {
            foreach ($rows as $line => $row) {
                $pin = $newPins ? null : $row['pin'];
                try {
                    $existing = $this->branches->findByCode($station, trim($row['code']));
                    if (null === $existing) {
                        [$branch, $pin] = $this->branches->create($station, $row['code'], $row['name'], $pin, $legacyPins);
                        $action = 'created';
                    } else {
                        $this->branches->update($existing, ['name' => $row['name']]);
                        $pin = $this->branches->setPin($existing, $pin, $legacyPins);
                        $branch = $existing;
                        $action = 'updated';
                    }
                    $report[] = [$line, $action, $branch->code, $branch->name, $newPins ? $pin : '(from CSV)'];
                } catch (ValidationException $e) {
                    $errors++;
                    $report[] = [$line, 'ERROR', $row['code'], $row['name'], $e->getMessage()];
                }
            }

            if ($dryRun || $errors > 0) {
                $this->em->rollback();
                $this->em->clear();
            } else {
                $this->em->commit();
            }
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        $io->table(['CSV line', 'Result', 'Code', 'Name', 'PIN'], $report);

        if ($errors > 0) {
            $io->error("$errors row(s) failed; nothing was saved. Fix the CSV and re-run.");
            return 1;
        }
        $dryRun
            ? $io->note(count($rows) . ' row(s) valid. Dry run: nothing saved.')
            : $io->success(count($rows) . ' branch(es) imported into station "' . $station->short_name . '".');
        if ($newPins && !$dryRun) {
            $io->warning('These PINs are shown only now. Store them securely before closing this window.');
        }
        return 0;
    }

    /** @return array<int, array{pin: string, name: string, code: string}>|null keyed by CSV line number */
    private function readCsv(string $path, SymfonyStyle $io): ?array
    {
        $fh = @fopen($path, 'rb');
        if (false === $fh) {
            $io->error("Cannot read $path");
            return null;
        }

        $header = array_map(
            static fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h) ?? '')),
            fgetcsv($fh, escape: '') ?: []
        );
        $find = static function (string $pattern) use ($header): ?int {
            foreach ($header as $i => $h) {
                if (preg_match($pattern, $h)) {
                    return $i;
                }
            }
            return null;
        };
        $cols = ['pin' => $find('/pin/'), 'name' => $find('/name/'), 'code' => $find('/\b(id|code)\b/')];
        if (in_array(null, $cols, true)) {
            $io->error('CSV needs PIN, name and ID/code columns. Found headers: ' . implode(', ', $header));
            fclose($fh);
            return null;
        }

        $rows = [];
        $line = 1;
        while (false !== ($r = fgetcsv($fh, escape: ''))) {
            $line++;
            if ([null] === $r || '' === trim(implode('', $r))) {
                continue;
            }
            $rows[$line] = [
                'pin' => trim((string)($r[$cols['pin']] ?? '')),
                'name' => (string)($r[$cols['name']] ?? ''),
                'code' => (string)($r[$cols['code']] ?? ''),
            ];
        }
        fclose($fh);
        return $rows;
    }
}
