<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Entity;

use App\Entity\Station;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-station Tracker settings. Read/written through Service\StationSettingsService (DBAL);
 * mapped here so Doctrine's schema matches the migration.
 */
#[
    ORM\Entity,
    ORM\Table(name: 'branch_station_settings')
]
class StationSettings
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Station $station;

    /** Serve the branch PIN login + player on the station's own public page (/public/{station}). */
    #[ORM\Column]
    public bool $public_page_login = false;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $updated_at;

    public function __construct(Station $station)
    {
        $this->station = $station;
        $this->updated_at = new DateTimeImmutable();
    }
}
