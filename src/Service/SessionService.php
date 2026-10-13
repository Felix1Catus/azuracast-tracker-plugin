<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use DateTimeImmutable;
use Plugin\BranchTracker\Entity\Branch;
use Plugin\BranchTracker\Entity\BranchSession;
use Plugin\BranchTracker\Entity\BranchSessionListener;
use Plugin\BranchTracker\Entity\Enums\SessionEndReason;

/**
 * Branch login sessions. Tokens are 32 random bytes (hex) handed to the player once; only their
 * sha256 is stored. Remember: persist() before flush() (AzuraCast uses explicit change tracking).
 */
final class SessionService
{
    use EntityManagerAwareTrait;

    public const int HEARTBEAT_SECONDS = 60;

    public function __construct(
        private readonly BranchService $branches,
    ) {
    }

    /**
     * Verifies the PIN and opens a session, ending the branch's previous one (one device per branch).
     *
     * @return array{0: BranchSession, 1: string}|null session + plain token, or null for a bad/disabled PIN
     */
    public function login(Station $station, string $pin, string $ip, string $userAgent): ?array
    {
        $branch = $this->branches->findByPin($station, $pin);
        if (!$branch instanceof Branch || !$branch->is_enabled) {
            return null;
        }

        $this->branches->endOpenSessions($branch, SessionEndReason::Replaced);

        $token = bin2hex(random_bytes(32));
        $session = new BranchSession($branch, self::hashToken($token), $ip, $userAgent);
        $this->em->persist($session);
        $this->em->flush();

        return [$session, $token];
    }

    /** Any session (open or ended) for this token on this station, so callers can say *why* it ended. */
    public function findByToken(Station $station, string $token): ?BranchSession
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $session = $this->em->getRepository(BranchSession::class)->findOneBy(['token_hash' => self::hashToken($token)]);
        return ($session instanceof BranchSession && $session->station->id === $station->id) ? $session : null;
    }

    public function heartbeat(BranchSession $session, ?int $volume, ?string $state): void
    {
        $session->last_seen_at = new DateTimeImmutable();
        if (null !== $volume) {
            $session->volume = max(0, min(100, $volume));
        }
        if (null !== $state && in_array($state, ['playing', 'paused', 'stopped', 'buffering'], true)) {
            $session->client_state = $state;
        }
        $this->em->persist($session);
        $this->em->flush();
    }

    /** The branch (not just this device) has read the notice; it isn't shown again anywhere. */
    public function markNoticeSeen(BranchSession $session, string $noticeId): void
    {
        if (BranchNotice::ID !== $noticeId) {
            return;
        }
        $branch = $session->branch;
        $branch->notice_seen = $noticeId;
        $this->em->persist($branch);
        $this->em->flush();
    }

    public function logout(BranchSession $session): void
    {
        $session->end(SessionEndReason::Logout);
        $this->em->persist($session);
        $this->em->flush();
    }

    /** Called from Icecast's listener_add auth: remember which Icecast client belongs to the session. */
    public function recordListener(BranchSession $session, int $listenerUid, ?string $ip = null, ?string $agent = null): void
    {
        $session->last_seen_at = new DateTimeImmutable();
        $this->em->persist($session);
        $this->em->persist(new BranchSessionListener($session, $listenerUid, $ip, $agent));
        $this->em->flush();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
