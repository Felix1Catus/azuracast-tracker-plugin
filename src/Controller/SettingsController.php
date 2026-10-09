<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\BranchTracker\Service\StationSettingsService;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /api/station/{station}/branches/settings          ("view branches")
 * PUT /api/station/{station}/branches/settings {public_page_login?: bool, public_board?: bool, regenerate_public_key?: bool}
 *     ("manage branches")
 */
final class SettingsController
{
    public function __construct(
        private readonly StationSettingsService $settings,
    ) {
    }

    public function getAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $response->withJson($this->settings->toArray($request->getStation()));
    }

    public function putAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $body = (array)$request->getParsedBody();
        if (array_key_exists('public_page_login', $body)) {
            $this->settings->setPublicPageLogin($station, filter_var($body['public_page_login'], FILTER_VALIDATE_BOOL));
        }
        if (array_key_exists('public_board', $body)) {
            $this->settings->setPublicBoard(
                $station,
                filter_var($body['public_board'], FILTER_VALIDATE_BOOL),
                filter_var($body['regenerate_public_key'] ?? false, FILTER_VALIDATE_BOOL)
            );
        }
        return $response->withJson($this->settings->toArray($station));
    }
}
