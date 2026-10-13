<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Middleware\Auth\ApiAuth;
use Plugin\BranchTracker\Permissions;
use Plugin\BranchTracker\Service\PinHasher;
use Psr\Http\Message\ResponseInterface;

/**
 * Admin dashboard: GET /branch-tracker/{station_id} (login + "view branches").
 * Self-contained page (templates/dashboard.html) that calls the branch admin/report APIs with the admin's
 * session; write calls carry AzuraCast's API CSRF token, like the core Vue app does.
 */
final class DashboardPageAction implements SingleActionInterface
{
    public function __construct(
        private readonly \Plugin\BranchTracker\Service\Favicon $favicon,
        private readonly \Plugin\BranchTracker\Service\SourceLink $sourceLink,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $public = isset($params['public_key']);   // PublicBoardController: read-only, no login

        $config = [
            'station' => $station->short_name,
            'stationId' => $station->id,
            'stationName' => $station->name,
            'timezone' => $station->timezone ?: 'UTC',
            'publicKey' => $public ? $params['public_key'] : null,
            'csrf' => $public ? null : $request->getCsrf()->generate(ApiAuth::API_CSRF_NAMESPACE),
            'canManage' => !$public && $request->getAcl()->isAllowed(Permissions::MANAGE, $station->id),
            'pinLength' => PinHasher::length(),
            'companies' => \Plugin\BranchTracker\Entity\Branch::COMPANIES,
            'version' => PingAction::VERSION,
        ];

        $html = strtr((string)file_get_contents(dirname(__DIR__, 2) . '/templates/dashboard.html'), [
            '{{STATION_NAME}}' => htmlspecialchars($station->name, ENT_QUOTES),
            '{{FAVICON}}' => $this->favicon->tags(),
            '{{SOURCE_LINK}}' => $this->sourceLink->html(),
            '{{CONFIG_JSON}}' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        ]);

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
