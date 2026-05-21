<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Bybit\ServerTime;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /healthz — публичный health-check.
 *
 * Проверки:
 *  - cron_minute: последний успешный ≤ 90 секунд назад
 *  - cron_hourly: последний успешный ≤ 70 минут назад
 *  - bybit (если settings.bybit_health_ping_enabled = true):
 *      последний успешный ping ≤ 2 часов назад
 *
 * См. spec.md §15.
 */
final class HealthController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $checks = [];
        $allOk  = true;

        try {
            $pdo = Database::pdo();
            $row = $pdo->query(
                "SELECT kind, MAX(started_at) AS last_run
                 FROM cron_runs WHERE status='success' GROUP BY kind"
            )->fetchAll();

            $byKind = array_column($row, 'last_run', 'kind');

            $now = time();
            $hourly = $byKind['hourly'] ?? null;
            $minute = $byKind['minute'] ?? null;

            $hourlyAgeSec = $hourly ? $now - strtotime($hourly) : null;
            $minuteAgeSec = $minute ? $now - strtotime($minute) : null;

            $checks['hourly'] = [
                'last_run' => $hourly,
                'age_sec'  => $hourlyAgeSec,
                'ok'       => $hourlyAgeSec !== null && $hourlyAgeSec <= 70 * 60,
            ];
            $checks['minute'] = [
                'last_run' => $minute,
                'age_sec'  => $minuteAgeSec,
                'ok'       => $minuteAgeSec !== null && $minuteAgeSec <= 90,
            ];
            $allOk = $checks['hourly']['ok'] && $checks['minute']['ok'];

            // ─── Bybit health (если включён) ───
            $pingEnabled = filter_var(
                Config::get('bybit_health_ping_enabled', null, false),
                FILTER_VALIDATE_BOOLEAN
            );
            if ($pingEnabled) {
                $bybit = ServerTime::lastSuccessful();
                $thresholdSec = 2 * 60 * 60; // 2 часа
                $ok = $bybit['age_sec'] !== null && $bybit['age_sec'] <= $thresholdSec;
                $checks['bybit'] = [
                    'last_ok'        => $bybit['last_ok_ts'],
                    'age_sec'        => $bybit['age_sec'],
                    'threshold_sec'  => $thresholdSec,
                    'ok'             => $ok,
                ];
                $allOk = $allOk && $ok;
            }
        } catch (\Throwable $e) {
            $checks['db'] = ['ok' => false, 'error' => $e->getMessage()];
            $allOk = false;
        }

        $payload = [
            'status' => $allOk ? 'ok' : 'degraded',
            'time'   => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
