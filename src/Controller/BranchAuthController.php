<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Container\LoggerAwareTrait;
use App\Container\SettingsAwareTrait;
use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\BranchTracker\Entity\BranchSession;
use Plugin\BranchTracker\Service\LoginThrottle;
use Plugin\BranchTracker\Service\SessionService;
use Psr\Http\Message\ResponseInterface;

/**
 * Public branch API used by the player page: /api/station/{station_id}/branch/{login,heartbeat,logout}
 * The PIN only ever travels in a POST body. Responses never include PINs.
 */
final class BranchAuthController
{
    use LoggerAwareTrait;
    use SettingsAwareTrait;

    public const int IP_ATTEMPTS = 5;        // per IP ...
    public const int IP_WINDOW_SECONDS = 60; // ... per minute (core rate limiter)

    public function __construct(
        private readonly SessionService $sessions,
        private readonly LoginThrottle $throttle,
    ) {
    }

    public function loginAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $ip = $this->readSettings()->getIp($request);

        // Per-IP limit (throws 429), then the station-wide failed-login cap.
        $request->getRateLimit()->checkRequestRateLimit(
            $request,
            'branch-login',
            self::IP_WINDOW_SECONDS,
            self::IP_ATTEMPTS
        );
        if ($this->throttle->isBlocked($station)) {
            return $this->fail($response, 429, 'too_many_attempts', 'Too many failed attempts. Try again in a few minutes.');
        }

        $pin = trim((string)($this->body($request)['pin'] ?? ''));
        $result = '' === $pin ? null : $this->sessions->login(
            $station,
            $pin,
            $ip,
            $request->getHeaderLine('User-Agent')
        );

        if (null === $result) {
            $this->throttle->recordFailure($station);
            $this->logger->notice('Branch login failed', ['station' => $station->short_name, 'ip' => $ip]);
            return $this->fail($response, 401, 'invalid_pin', 'Invalid PIN.');
        }

        [$session, $token] = $result;
        $this->logger->info('Branch login', [
            'station' => $station->short_name,
            'branch' => $session->branch->code,
            'ip' => $ip,
        ]);

        return $response->withJson([
            'success' => true,
            'token' => $token,
            'branch' => ['code' => $session->branch->code, 'name' => $session->branch->name],
            'heartbeat_seconds' => SessionService::HEARTBEAT_SECONDS,
        ]);
    }

    /** Body: {token, volume?, state?}. 401 with a reason once the session has ended. */
    public function heartbeatAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = $this->body($request);
        $session = $this->sessions->findByToken($request->getStation(), (string)($body['token'] ?? ''));
        if (null !== ($error = $this->rejectIfClosed($response, $session))) {
            return $error;
        }

        $this->sessions->heartbeat(
            $session,
            isset($body['volume']) && is_numeric($body['volume']) ? (int)$body['volume'] : null,
            isset($body['state']) ? (string)$body['state'] : null
        );

        return $response->withJson(['success' => true, 'heartbeat_seconds' => SessionService::HEARTBEAT_SECONDS]);
    }

    /** Body: {token}. Accepts navigator.sendBeacon (text/plain JSON body). Idempotent. */
    public function logoutAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $session = $this->sessions->findByToken($request->getStation(), (string)($this->body($request)['token'] ?? ''));
        if (null !== $session && $session->isOpen()) {
            $this->sessions->logout($session);
        }
        return $response->withJson(['success' => true]);
    }

    private function rejectIfClosed(Response $response, ?BranchSession $session): ?ResponseInterface
    {
        if (null === $session) {
            return $this->fail($response, 401, 'invalid_token', 'Not signed in.');
        }
        if (!$session->isOpen()) {
            return $this->fail($response, 401, $session->end_reason?->value ?? 'ended', match ($session->end_reason?->value) {
                'replaced' => 'This branch was signed in on another device.',
                'daily_reset' => 'Daily sign-in reset. Please enter your PIN again.',
                'admin' => 'Your access was changed by an administrator. Please sign in again.',
                'timeout' => 'Signed out after inactivity.',
                default => 'Signed out.',
            });
        }
        return null;
    }

    /** JSON or form body; also raw text/plain JSON from sendBeacon. */
    private function body(ServerRequest $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && [] !== $parsed) {
            return $parsed;
        }
        $raw = (string)$request->getBody();
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    private function fail(Response $response, int $status, string $reason, string $message): ResponseInterface
    {
        return $response->withStatus($status)->withJson([
            'success' => false,
            'reason' => $reason,
            'message' => $message,
            'formatted_message' => $message,
        ]);
    }
}
