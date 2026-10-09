<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Controller\SingleActionInterface;
use App\Exception\Http\PermissionDeniedException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Utilities\Types;
use Plugin\BranchTracker\Service\SessionService;
use Plugin\BranchTracker\StreamAuthMode;
use Psr\Http\Message\ResponseInterface;

/**
 * Icecast URL-auth `listener_add` target, set on the mount by `branch-tracker:stream-auth`.
 * Route: /api/branch-tracker/internal/{station_id}/listener-auth/{mode}/{api_auth}
 *
 * Icecast POSTs: action, client (== listener.listener_uid), mount ("/radio.mp3?bt=<token>&..."),
 * ip, agent, ... (verified in phase 0). We answer with the icecast-auth-user header.
 *  - valid open session token: allow, and link the Icecast client to the session
 *  - otherwise: deny in "enforce" mode, allow in "observe" mode (lets the pilot run with the stream open)
 */
final class IcecastAuthAction implements SingleActionInterface
{
    public function __construct(
        private readonly SessionService $sessions,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $authKey = Types::stringOrNull($params['api_auth'] ?? null, true);
        if (null === $authKey || !$station->validateAdapterApiKey($authKey)) {
            throw PermissionDeniedException::create($request);
        }
        $mode = StreamAuthMode::tryFrom((string)($params['mode'] ?? '')) ?? StreamAuthMode::Enforce;

        parse_str((string)parse_url(Types::string($request->getParam('mount')), PHP_URL_QUERY), $query);
        $token = is_string($query['bt'] ?? null) ? $query['bt'] : '';
        $session = '' === $token ? null : $this->sessions->findByToken($station, $token);

        if (null !== $session && $session->isOpen()) {
            $uid = Types::int($request->getParam('client'));
            if ($uid > 0) {
                $this->sessions->recordListener(
                    $session,
                    $uid,
                    Types::stringOrNull($request->getParam('ip'), true),
                    Types::stringOrNull($request->getParam('agent'), true)
                );
            }
            return $response->withHeader('icecast-auth-user', '1');
        }

        if (StreamAuthMode::Observe === $mode) {
            return $response->withHeader('icecast-auth-user', '1');
        }

        return $response
            ->withHeader('icecast-auth-user', '0')
            ->withHeader('icecast-auth-message', 'branch login required');
    }
}
