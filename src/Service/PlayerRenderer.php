<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use App\Entity\Station;

/** Renders templates/player.html (PIN login + branch player) for a station. Used by both player URLs. */
final class PlayerRenderer
{
    public function render(Station $station): string
    {
        $config = [
            'station' => $station->short_name,
            'stationName' => $station->name,
            'heartbeat' => SessionService::HEARTBEAT_SECONDS,
            'pinLength' => PinHasher::length(),
            'offlineText' => $station->branding_config->offline_text ?? null,
        ];

        return strtr((string)file_get_contents(dirname(__DIR__, 2) . '/templates/player.html'), [
            '{{STATION_NAME}}' => htmlspecialchars($station->name, ENT_QUOTES),
            '{{PIN_LENGTH}}' => (string)$config['pinLength'],
            '{{CONFIG_JSON}}' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        ]);
    }
}
