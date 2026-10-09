<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;

/** Per-station Tracker settings + derived state (DBAL; one tiny row per station). */
final class StationSettingsService
{
    use EntityManagerAwareTrait;

    /** True when /public/{station} should show the branch PIN login + player instead of the stock page. */
    public function publicPageLogin(Station $station): bool
    {
        return $this->publicPageLoginSetting($station) || $this->isEnforced($station);
    }

    public function publicPageLoginSetting(Station $station): bool
    {
        return (bool)$this->em->getConnection()->fetchOne(
            'SELECT public_page_login FROM branch_station_settings WHERE station_id = :s',
            ['s' => $station->id]
        );
    }

    public function setPublicPageLogin(Station $station, bool $on): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO branch_station_settings (station_id, public_page_login, updated_at)
             VALUES (:s, :on, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE public_page_login = VALUES(public_page_login), updated_at = UTC_TIMESTAMP()',
            ['s' => $station->id, 'on' => $on ? 1 : 0]
        );
    }

    /** Stream auth "enforce" on any mount: the stock page can't play, so the branch login always applies. */
    public function isEnforced(Station $station): bool
    {
        return (bool)$this->em->getConnection()->fetchOne(
            "SELECT 1 FROM station_mounts WHERE station_id = :s AND frontend_config LIKE '%/listener-auth/enforce/%' LIMIT 1",
            ['s' => $station->id]
        );
    }

    public function streamAuthMode(Station $station): string
    {
        $cfg = (string)$this->em->getConnection()->fetchOne(
            "SELECT GROUP_CONCAT(frontend_config) FROM station_mounts WHERE station_id = :s",
            ['s' => $station->id]
        );
        return preg_match('#/listener-auth/(observe|enforce)/#', $cfg, $m) ? $m[1] : 'off';
    }

    /** @return array{public_page_login: bool, public_page_login_effective: bool, stream_auth: string} */
    public function toArray(Station $station): array
    {
        return [
            'public_page_login' => $this->publicPageLoginSetting($station),
            'public_page_login_effective' => $this->publicPageLogin($station),
            'stream_auth' => $this->streamAuthMode($station),
        ];
    }
}
