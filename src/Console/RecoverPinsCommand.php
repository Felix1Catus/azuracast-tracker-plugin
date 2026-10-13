<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Console;

use App\Console\Command\CommandAbstract;
use App\Container\EntityManagerAwareTrait;
use Plugin\BranchTracker\Entity\Branch;
use Plugin\BranchTracker\Service\PinHasher;
use Plugin\BranchTracker\Service\PinVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time: fill the managers' PIN column for branches whose PIN was set before PINs were kept (encrypted).
 * PINs are short, so each one is found by trying every PIN of that length against the branch's HMAC with this
 * install's PIN key. Only branches without a stored PIN are touched; PINs and logins don't change.
 */
#[AsCommand(
    name: 'branch-tracker:recover-pins',
    description: 'Fill the board\'s PIN column for branches created before PINs were stored (found from their hash).',
)]
final class RecoverPinsCommand extends CommandAbstract
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly PinHasher $hasher,
        private readonly PinVault $vault,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-digits', null, InputOption::VALUE_REQUIRED, 'Longest PIN to try (4-8; 6+ is slow)', '6');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maxDigits = max(4, min(8, (int)$input->getOption('max-digits')));

        /** @var Branch[] $branches */
        $branches = $this->em->createQuery(
            'SELECT b FROM Plugin\BranchTracker\Entity\Branch b WHERE b.pin_encrypted IS NULL ORDER BY b.id'
        )->getResult();
        if ([] === $branches) {
            $io->success('Every branch already has its PIN stored.');
            return 0;
        }

        // Group by station: the HMAC covers "station id:PIN", so one pass per station and length finds them all.
        $byStation = [];
        foreach ($branches as $b) {
            $byStation[$b->station->id][$b->pin_hmac] = $b;
        }

        $found = 0;
        foreach ($byStation as $stationId => $wanted) {
            for ($len = 4; $len <= $maxDigits && [] !== $wanted; $len++) {
                for ($n = 0, $max = 10 ** $len; $n < $max && [] !== $wanted; $n++) {
                    $pin = str_pad((string)$n, $len, '0', STR_PAD_LEFT);
                    $hmac = $this->hasher->hash($stationId, $pin);
                    if (isset($wanted[$hmac])) {
                        $wanted[$hmac]->pin_encrypted = $this->vault->encrypt($pin);
                        $this->em->persist($wanted[$hmac]);
                        unset($wanted[$hmac]);
                        $found++;
                    }
                }
            }
            foreach ($wanted as $b) {
                $io->warning(sprintf('%s (%s): not found up to %d digits; set a new PIN for it.', $b->name, $b->code, $maxDigits));
            }
        }
        $this->em->flush();

        $io->success(sprintf('Stored the PIN of %d of %d branch(es).', $found, count($branches)));
        return 0;
    }
}
