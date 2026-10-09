<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

/** Serves templates/nav.js (the "Branches" button script injected into AzuraCast station pages). */
final class NavScriptAction implements SingleActionInterface
{
    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $response->getBody()->write((string)file_get_contents(dirname(__DIR__, 2) . '/templates/nav.js'));
        return $response
            ->withHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache');
    }
}
