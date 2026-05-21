<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Core\Config;
use BybitBot\Web\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Контроллер авторизации: /login (GET, POST), /logout.
 */
final class AuthController
{
    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $error = $request->getQueryParams()['error'] ?? null;
        return Twig::fromRequest($request)->render($response, 'login.twig', [
            'error' => $error,
        ]);
    }

    public function doLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $totp     = trim((string)($body['totp']     ?? ''));
        $ip       = $this->clientIp($request);

        $result = Auth::login($username, $password, $totp, $ip);
        if (!$result['ok']) {
            return $response->withHeader('Location', '/login?error=' . urlencode($result['reason'] ?? 'failed'))
                            ->withStatus(302);
        }

        $ttl = (int)Config::bootstrap('session.ttl_hours', 8);
        $token = Auth::createSession(
            $result['user_id'],
            $ip,
            (string)($request->getServerParams()['HTTP_USER_AGENT'] ?? ''),
            $ttl
        );

        // HttpOnly + Secure + SameSite=Strict (см. §14.1)
        $cookie = sprintf(
            'bbsess=%s; Path=/; HttpOnly; SameSite=Strict; Max-Age=%d%s',
            $token,
            $ttl * 3600,
            ((bool)Config::bootstrap('app.debug', false)) ? '' : '; Secure',
        );
        return $response->withHeader('Set-Cookie', $cookie)
                        ->withHeader('Location', '/')
                        ->withStatus(302);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getCookieParams()['bbsess'] ?? '';
        if ($token !== '') {
            Auth::destroySession($token);
        }
        return $response->withHeader('Set-Cookie', 'bbsess=; Path=/; Max-Age=0')
                        ->withHeader('Location', '/login')
                        ->withStatus(302);
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        return (string)($server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
