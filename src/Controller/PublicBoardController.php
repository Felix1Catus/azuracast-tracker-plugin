<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\BranchTracker\Service\StationSettingsService;
use Psr\Http\Message\ResponseInterface;

/**
 * Public read-only board (no login), switched on per station from the board's Manage tab.
 *   GET /branch-tracker/{station}/public/{key}                               the page
 *   GET /api/station/{station}/branch-board/{key}/{live|sessions|report}      its data
 * Delegates to the admin actions with the request marked public, so they drop IP addresses and
 * devices and refuse CSV. There are no write actions here. Wrong key or board off: 404.
 */
final class PublicBoardController
{
    public const string ATTR = 'branch_tracker_public';

    public function __construct(
        private readonly StationSettingsService $settings,
        private readonly ReportsController $reports,
        private readonly DashboardPageAction $page,
    ) {
    }

    public function pageAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->allowed($request, $params)
            ? ($this->page)($request, $response, $params + ['public_key' => (string)$params['key']])
            : $this->notFound($response);
    }

    public function liveAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->allowed($request, $params)
            ? $this->reports->liveAction($request->withAttribute(self::ATTR, true), $response, $params)
            : $this->notFound($response);
    }

    public function sessionsAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->allowed($request, $params)
            ? $this->reports->sessionsAction($request->withAttribute(self::ATTR, true), $response, $params)
            : $this->notFound($response);
    }

    public function reportAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->allowed($request, $params)
            ? $this->reports->reportAction($request->withAttribute(self::ATTR, true), $response, $params)
            : $this->notFound($response);
    }

    private function allowed(ServerRequest $request, array $params): bool
    {
        $key = $this->settings->publicBoardKey($request->getStation());
        return null !== $key && is_string($params['key'] ?? null) && hash_equals($key, $params['key']);
    }

    private function notFound(Response $response): ResponseInterface
    {
        return $response->withStatus(404)->withJson(['success' => false, 'message' => 'Not found.']);
    }
}
