<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds the "Branches" button to AzuraCast's own station pages. AzuraCast has no plugin hook for its
 * (precompiled Vue) menus, so this appends one <script> tag to the HTML of AzuraCast panel pages (it is a single-page app, so the user may enter via /dashboard and click into a station); the
 * script (templates/nav.js) shows the button only if the logged-in user may view the branch board.
 * Any failure leaves the page untouched.
 */
final class InjectNavButton implements MiddlewareInterface
{
    public const string SCRIPT_PATH = '/branch-tracker-assets/nav.js';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        try {
            if (
                'GET' !== $request->getMethod()
                || !preg_match('#^/(station|dashboard|admin|profile)(/|$)#', $request->getUri()->getPath())
                || 200 !== $response->getStatusCode()
                || !str_contains($response->getHeaderLine('Content-Type'), 'text/html')
            ) {
                return $response;
            }

            $html = (string)$response->getBody();
            $pos = strripos($html, '</body>');
            if (false === $pos || str_contains($html, self::SCRIPT_PATH)) {
                return $response;
            }

            $tag = '<script src="' . self::SCRIPT_PATH . '" defer></script>';
            $body = \GuzzleHttp\Psr7\Utils::streamFor(substr($html, 0, $pos) . $tag . substr($html, $pos));

            return $response->withBody($body)->withoutHeader('Content-Length');
        } catch (\Throwable) {
            return $response;
        }
    }
}
