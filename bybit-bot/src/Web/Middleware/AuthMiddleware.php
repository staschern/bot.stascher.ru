<?php
declare(strict_types=1);

namespace BybitBot\Web\Middleware;

use BybitBot\Core\Config;
use BybitBot\Web\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Middleware: проверяет cookie-сессию. При отсутствии — редирект на /login.
 *
 * См. spec.md §14.1.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $token   = $cookies['bbsess'] ?? '';
        $idle    = (int)Config::bootstrap('session.idle_hours', 1);

        $sess = Auth::checkSession($token, $idle);
        if ($sess === null) {
            $resp = new Response();
            return $resp->withHeader('Location', '/login')->withStatus(302);
        }

        $request = $request->withAttribute('user_id', $sess['user_id']);
        return $handler->handle($request);
    }
}
