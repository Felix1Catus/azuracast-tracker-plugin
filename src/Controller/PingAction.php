<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

/** Health check: proves the plugin is loaded and its tables exist. No sensitive data. */
final class PingAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public const string VERSION = '1.3.0';

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $db = $this->em->getConnection();

        return $response->withJson([
            'plugin' => 'branch-tracker',
            'version' => self::VERSION,
            'ok' => true,
            'branches' => (int)$db->fetchOne('SELECT COUNT(*) FROM branch_branch'),
            'open_sessions' => (int)$db->fetchOne('SELECT COUNT(*) FROM branch_session WHERE ended_at IS NULL'),
        ]);
    }
}
