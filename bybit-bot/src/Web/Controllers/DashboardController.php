<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Core\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Дашборд — заглушка для Этапа 1.
 * Полное наполнение (счётчики позиций, P/L, цветовая шкала депозита) — Этап 2.
 *
 * См. spec.md §14.2 п.1, §14.6.
 */
final class DashboardController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'dashboard.twig', [
            'mode'        => Config::get('mode', null, 'paper'),
            'app_version' => (string)Config::bootstrap('app.version', '0.7.0'),
            'placeholder' => true,
        ]);
    }
}
