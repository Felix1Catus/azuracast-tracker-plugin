<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use App\Container\SettingsAwareTrait;
use App\Entity\Station;

/** Renders templates/player.html (PIN login + branch player) for a station. Used by both player URLs. */
final class PlayerRenderer
{
    use SettingsAwareTrait;

    public function __construct(
        private readonly Favicon $favicon,
    ) {
    }

    public function render(Station $station): string
    {
        $config = [
            'station' => $station->short_name,
            'stationName' => $station->name,
            // Wordmark at the top: the AzuraCast instance name (Administration > Settings), else the station name.
            'brand' => trim((string)($this->readSettings()->instance_name ?? '')) ?: $station->name,
            'heartbeat' => SessionService::HEARTBEAT_SECONDS,
            'pinLength' => PinHasher::length(),
            'offlineText' => $station->branding_config->offline_text ?? null,
        ];

        return strtr((string)file_get_contents(dirname(__DIR__, 2) . '/templates/player.html'), [
            '{{STATION_NAME}}' => htmlspecialchars($station->name, ENT_QUOTES),
            '{{PIN_LENGTH}}' => (string)$config['pinLength'],
            '{{FAVICON}}' => $this->favicon->tags(),
            '{{CONFIG_JSON}}' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        ]);
    }
}
