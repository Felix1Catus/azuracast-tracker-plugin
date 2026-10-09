<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use App\Entity\Station;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Station-wide cap on FAILED PIN logins, on top of core's per-IP rate limit.
 * Login is PIN-only (no branch code), so a wrong PIN can't be pinned on one branch; this caps how fast
 * the whole PIN space can be swept from many IPs. Fixed window, kept in AzuraCast's cache (Redis).
 */
final class LoginThrottle
{
    public const int MAX_FAILURES = 20;
    public const int WINDOW_SECONDS = 300;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function isBlocked(Station $station): bool
    {
        return $this->read($station)['count'] >= self::MAX_FAILURES;
    }

    public function recordFailure(Station $station): void
    {
        $data = $this->read($station);
        $data['count']++;

        $item = $this->cache->getItem($this->key($station));
        $item->set($data);
        $item->expiresAt((new \DateTimeImmutable())->setTimestamp($data['window_start'] + self::WINDOW_SECONDS));
        $this->cache->save($item);
    }

    /** @return array{count: int, window_start: int} */
    private function read(Station $station): array
    {
        $item = $this->cache->getItem($this->key($station));
        $data = $item->isHit() ? $item->get() : null;
        if (!is_array($data) || ($data['window_start'] ?? 0) + self::WINDOW_SECONDS < time()) {
            return ['count' => 0, 'window_start' => time()];
        }
        return $data;
    }

    private function key(Station $station): string
    {
        return 'branch_tracker.login_failures.' . $station->id;
    }
}
