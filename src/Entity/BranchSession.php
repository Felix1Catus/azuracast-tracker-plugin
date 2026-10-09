<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Entity;

use App\Entity\Station;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Plugin\BranchTracker\Entity\Enums\SessionEndReason;

/** One login of a branch, from PIN entry until logout / timeout / replacement / daily reset. */
#[
    ORM\Entity,
    ORM\Table(name: 'branch_session'),
    ORM\UniqueConstraint(name: 'uniq_branch_session_token', columns: ['token_hash']),
    ORM\Index(name: 'idx_branch_session_open', columns: ['branch_id', 'ended_at']),
    ORM\Index(name: 'idx_branch_session_station_time', columns: ['station_id', 'started_at'])
]
class BranchSession
{
    #[ORM\Column, ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Branch $branch;

    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Station $station;

    /** sha256 of the session token handed to the player. The token itself is never stored. */
    #[ORM\Column(length: 64)]
    public readonly string $token_hash;

    #[ORM\Column(type: 'datetime_immutable', precision: 6)]
    public readonly DateTimeImmutable $started_at;

    #[ORM\Column(type: 'datetime_immutable', precision: 6)]
    public DateTimeImmutable $last_seen_at;

    #[ORM\Column(type: 'datetime_immutable', precision: 6, nullable: true)]
    public ?DateTimeImmutable $ended_at = null;

    #[ORM\Column(length: 20, nullable: true, enumType: SessionEndReason::class)]
    public ?SessionEndReason $end_reason = null;

    #[ORM\Column(length: 45)]
    public string $ip;

    #[ORM\Column(length: 255)]
    public string $user_agent;

    /** Last volume (0-100) reported by the player; the server can't observe this itself. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    public ?int $volume = null;

    /** Last client-reported state (playing / paused / stopped). */
    #[ORM\Column(length: 20, nullable: true)]
    public ?string $client_state = null;

    public function __construct(Branch $branch, string $tokenHash, string $ip, string $userAgent)
    {
        $this->branch = $branch;
        $this->station = $branch->station;
        $this->token_hash = $tokenHash;
        $this->started_at = new DateTimeImmutable();
        $this->last_seen_at = $this->started_at;
        $this->ip = $ip;
        $this->user_agent = mb_substr($userAgent, 0, 255);
    }

    public function isOpen(): bool
    {
        return null === $this->ended_at;
    }

    public function end(SessionEndReason $reason, ?DateTimeImmutable $at = null): void
    {
        if (!$this->isOpen()) {
            return;
        }
        $this->ended_at = $at ?? new DateTimeImmutable();
        $this->end_reason = $reason;
    }
}
