<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\BranchTracker\Service\PlayerRenderer;
use Psr\Http\Message\ResponseInterface;

/**
 * The branch player at /public/{station_id}/branch (always available). The same page is served on the
 * station's own /public/{station_id} when "public page login" is on (Middleware\PublicPageTakeover).
 * Self-contained (templates/player.html), so AzuraCast UI changes can't break it.
 */
final class PlayerPageAction implements SingleActionInterface
{
    public function __construct(
        private readonly PlayerRenderer $renderer,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $response->getBody()->write($this->renderer->render($request->getStation()));
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
