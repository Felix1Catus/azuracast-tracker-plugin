<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Entity;

use App\Entity\Station;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** A store branch that logs in to the station's player with a PIN. */
#[
    ORM\Entity,
    ORM\Table(name: 'branch_branch'),
    ORM\UniqueConstraint(name: 'uniq_branch_station_code', columns: ['station_id', 'code']),
    ORM\UniqueConstraint(name: 'uniq_branch_station_pin', columns: ['station_id', 'pin_hmac']),
    ORM\HasLifecycleCallbacks
]
class Branch
{
    #[ORM\Column, ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Station $station;

    /** Human-facing branch ID, e.g. "HUB-001". */
    #[ORM\Column(length: 50)]
    public string $code;

    #[ORM\Column(length: 100)]
    public string $name;

    /** HMAC-SHA256 of the PIN (see Service\PinHasher). Never the PIN itself. */
    #[ORM\Column(length: 64)]
    public string $pin_hmac;

    #[ORM\Column]
    public bool $is_enabled = true;

    /** Consecutive failed PIN attempts that matched this branch's code, for lockout (phase 2). */
    #[ORM\Column]
    public int $failed_attempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $locked_until = null;

    /** Companies a branch can belong to (the board's company dropdown and filter). */
    public const array COMPANIES = ['Tradeline', 'XPRS'];

    /** The company the branch belongs to (one of Branch::COMPANIES), or null when not set. */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $company = null;

    /** The PIN, encrypted (Service\PinVault), for the managers' PIN column. Null until set or recovered. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $pin_encrypted = null;

    /** Id of the last "what's new" notice (Service\BranchNotice) this branch dismissed. */
    #[ORM\Column(length: 40, nullable: true)]
    public ?string $notice_seen = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $created_at;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $updated_at;

    public function __construct(Station $station, string $code, string $name, string $pinHmac)
    {
        $this->station = $station;
        $this->code = $code;
        $this->name = $name;
        $this->pin_hmac = $pinHmac;
        $this->created_at = new DateTimeImmutable();
        $this->updated_at = $this->created_at;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updated_at = new DateTimeImmutable();
    }

    /** API representation. Deliberately has no PIN field. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_enabled' => $this->is_enabled,
            'company' => $this->company,
            'locked_until' => $this->locked_until?->format(DATE_ATOM),
            'created_at' => $this->created_at->format(DATE_ATOM),
            'updated_at' => $this->updated_at->format(DATE_ATOM),
        ];
    }
}
