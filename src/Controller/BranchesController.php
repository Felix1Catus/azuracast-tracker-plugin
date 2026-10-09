<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\BranchTracker\Entity\Branch;
use Plugin\BranchTracker\Exception\ValidationException;
use Plugin\BranchTracker\Service\BranchService;
use Psr\Http\Message\ResponseInterface;

/**
 * Admin API: /api/station/{station_id}/branches
 * PINs are write-only: returned once on create / PIN reset, never readable afterwards.
 */
final class BranchesController
{
    public function __construct(
        private readonly BranchService $branches,
    ) {
    }

    public function listAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $rows = array_map(
            fn(Branch $b) => $b->toArray() + ['has_open_session' => null !== $this->branches->getOpenSession($b)],
            $this->branches->listForStation($request->getStation())
        );
        return $response->withJson($rows);
    }

    public function getAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $branch = $this->branches->find($request->getStation(), (int)$params['branch_id']);
        return null === $branch
            ? $this->error($response, 404, 'Branch not found.')
            : $response->withJson($branch->toArray());
    }

    public function createAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        return $this->guard($response, function () use ($request, $response, $body) {
            [$branch, $pin] = $this->branches->create(
                $request->getStation(),
                (string)($body['code'] ?? ''),
                (string)($body['name'] ?? ''),
                isset($body['pin']) ? (string)$body['pin'] : null
            );
            if (isset($body['is_enabled'])) {
                $this->branches->update($branch, ['is_enabled' => $body['is_enabled']]);
            }
            return $response->withStatus(201)->withJson($branch->toArray() + ['pin' => $pin]);
        });
    }

    public function updateAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $branch = $this->branches->find($request->getStation(), (int)$params['branch_id']);
        if (null === $branch) {
            return $this->error($response, 404, 'Branch not found.');
        }
        $body = array_intersect_key((array)$request->getParsedBody(), array_flip(['code', 'name', 'is_enabled']));

        return $this->guard(
            $response,
            fn() => $response->withJson($this->branches->update($branch, $body)->toArray())
        );
    }

    public function deleteAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $branch = $this->branches->find($request->getStation(), (int)$params['branch_id']);
        if (null === $branch) {
            return $this->error($response, 404, 'Branch not found.');
        }
        $this->branches->delete($branch);
        return $response->withJson(['success' => true]);
    }

    /** POST: sign out every branch of the station; players show the PIN screen on their next check-in (<= 60 s). */
    public function logoutAllAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $count = $this->branches->endAllOpenSessions(
            $request->getStation(),
            \Plugin\BranchTracker\Entity\Enums\SessionEndReason::LogoutAll
        );
        return $response->withJson(['success' => true, 'signed_out' => $count]);
    }

    /** POST {pin?}: set a specific PIN, or omit it to generate one. Ends the branch's open session. */
    public function setPinAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $branch = $this->branches->find($request->getStation(), (int)$params['branch_id']);
        if (null === $branch) {
            return $this->error($response, 404, 'Branch not found.');
        }
        $body = (array)$request->getParsedBody();

        return $this->guard($response, fn() => $response->withJson([
            'id' => $branch->id,
            'code' => $branch->code,
            'pin' => $this->branches->setPin($branch, isset($body['pin']) ? (string)$body['pin'] : null),
        ]));
    }

    private function guard(Response $response, callable $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            return $this->error($response, $e->conflict ? 409 : 400, $e->getMessage());
        }
    }

    private function error(Response $response, int $status, string $message): ResponseInterface
    {
        return $response->withStatus($status)->withJson([
            'success' => false,
            'message' => $message,
            'formatted_message' => $message,
        ]);
    }
}
