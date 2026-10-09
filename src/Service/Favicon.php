<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use App\Assets\BrowserIconCustomAsset;

/**
 * Favicon tags for the plugin's pages, using AzuraCast's own browser icon (the one uploaded under
 * Administration > Branding, or AzuraCast's default), so plugin pages match the rest of the site.
 */
final class Favicon
{
    public function __construct(
        private readonly BrowserIconCustomAsset $icon,
    ) {
    }

    public function tags(): string
    {
        try {
            $e = static fn(string $u) => htmlspecialchars($u, ENT_QUOTES);
            return implode("\n", [
                '<link rel="icon" type="image/png" sizes="32x32" href="' . $e($this->icon->getUrlForSize(32)) . '">',
                '<link rel="icon" type="image/png" sizes="192x192" href="' . $e($this->icon->getUrlForSize(192)) . '">',
                '<link rel="apple-touch-icon" sizes="180x180" href="' . $e($this->icon->getUrlForSize(180)) . '">',
            ]);
        } catch (\Throwable) {
            return '';
        }
    }
}
