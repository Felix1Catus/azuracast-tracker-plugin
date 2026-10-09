<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Sync;

use App\Entity\Station;
use App\Sync\Task\AbstractTask;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Plugin\BranchTracker\Service\PresenceService;

/**
 * Every minute (azuracast:sync:run): link Icecast connections to sessions, end idle sessions,
 * and apply the 00:30 daily reset, for every station that has branches.
 */
final class PresenceTask extends AbstractTask
{
    public function __construct(
        private readonly PresenceService $presence,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '* * * * *';
    }

    public function run(bool $force = false): void
    {
        try {
            $stationIds = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT station_id FROM branch_branch');
        } catch (TableNotFoundException) {
            return; // plugin installed but migrations not run yet (first boot / restore in progress)
        }
        foreach ($stationIds as $id) {
            $station = $this->em->find(Station::class, (int)$id);
            if (!$station instanceof Station) {
                continue;
            }
            $result = $this->presence->sync($station);
            if (array_sum($result) > 0) {
                $this->logger->info('Branch tracker sync', ['station' => $station->short_name] + $result);
            }
        }
    }
}
