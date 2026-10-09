<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Middleware;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use Plugin\BranchTracker\Service\PlayerRenderer;
use Plugin\BranchTracker\Service\StationSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Hooks the branch PIN login + player into a station's existing public page, /public/{station}:
 * when the station's "public page login" is on (or its stream is locked with "enforce"), that URL serves
 * the Tracker player instead of AzuraCast's stock page. Same URL, so branches keep their bookmarks.
 * Embeds and other /public/{station}/... pages are untouched. Any failure falls through to the stock page.
 */
final class PublicPageTakeover implements MiddlewareInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationSettingsService $settings,
        private readonly PlayerRenderer $renderer,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            if ('GET' === $request->getMethod()
                && preg_match('#^/public/([^/]+)/?$#', $request->getUri()->getPath(), $m)
            ) {
                $key = urldecode($m[1]);
                $station = $this->em->getRepository(Station::class)->findOneBy(
                    ctype_digit($key) ? ['id' => (int)$key] : ['short_name' => $key]
                );
                if ($station instanceof Station && $station->enable_public_page && $this->settings->publicPageLogin($station)) {
                    return (new \GuzzleHttp\Psr7\Response(200))
                        ->withHeader('Content-Type', 'text/html; charset=utf-8')
                        ->withHeader('Cache-Control', 'no-store')
                        ->withBody(\GuzzleHttp\Psr7\Utils::streamFor($this->renderer->render($station)));
                }
            }
        } catch (\Throwable) {
        }
        return $handler->handle($request);
    }
}
