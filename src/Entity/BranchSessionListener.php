<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Entity;

use App\Entity\Listener;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ties a branch session to an Icecast connection. Written when Icecast's listener_add auth call
 * accepts the session token. `listener_id` is filled in later (PresenceService::linkListeners) by matching
 * core's `listener` rows: first on (listener_uid, fresh timestamp_start), because Icecast reuses client ids
 * after a restart; else on (IP, user agent, row open at auth time), because core keys listener rows on
 * md5(ip + agent + mount), so a reconnecting device continues its old row under the old uid.
 */
#[
    ORM\Entity,
    ORM\Table(name: 'branch_session_listener'),
    ORM\Index(name: 'idx_bsl_uid_auth', columns: ['listener_uid', 'auth_at'])
]
class BranchSessionListener
{
    #[ORM\Column, ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BranchSession::class)]
    #[ORM\JoinColumn(name: 'session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly BranchSession $session;

    /** Icecast client id (== listener.listener_uid). */
    #[ORM\Column]
    public readonly int $listener_uid;

    #[ORM\Column(type: 'datetime_immutable', precision: 6)]
    public readonly DateTimeImmutable $auth_at;

    #[ORM\ManyToOne(targetEntity: Listener::class)]
    #[ORM\JoinColumn(name: 'listener_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?Listener $listener = null;

    /** IP and user agent as Icecast reported them (same source as core's listener rows). */
    #[ORM\Column(length: 45, nullable: true)]
    public readonly ?string $listener_ip;

    #[ORM\Column(length: 255, nullable: true)]
    public readonly ?string $listener_agent;

    public function __construct(BranchSession $session, int $listenerUid, ?string $ip = null, ?string $agent = null)
    {
        $this->session = $session;
        $this->listener_uid = $listenerUid;
        $this->auth_at = new DateTimeImmutable();
        $this->listener_ip = null === $ip ? null : mb_substr($ip, 0, 45);
        $this->listener_agent = null === $agent ? null : mb_substr($agent, 0, 255);
    }
}
