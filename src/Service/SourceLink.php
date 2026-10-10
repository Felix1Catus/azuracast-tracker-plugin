<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

/**
 * "Source code" link for the plugin's pages. AzuraCast Retail serves the running source at /source
 * (AGPL-3.0 section 13); on a stock AzuraCast install that route doesn't exist and no link is shown.
 */
final class SourceLink
{
    public function html(): string
    {
        if (!class_exists('App\\Controller\\Frontend\\SourceCodeAction')) {
            return '';
        }

        return '<a class="src" href="/source" title="Source code of this server (GNU AGPL v3)">Source code</a>';
    }
}
