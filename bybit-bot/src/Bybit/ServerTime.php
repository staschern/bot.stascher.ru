<?php
declare(strict_types=1);

namespace BybitBot\Bybit;

use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;

/**
 * Health-проверки соединения с Bybit.
 *
 * Используется в:
 *  - cron_hourly: ping раз в час, результат пишется в `bybit_health_pings`
 *  - /healthz: проверка что последний успешный ping был ≤ 2 часов назад
 *  - bin/cli.php bybit:server-time / bybit:auth-check — ручная проверка
 */
final class ServerTime
{
    /**
     * Запросить серверное время Bybit (публичный endpoint, без подписи).
     *
     * @return array{ok:bool, time_ms:int|null, drift_ms:int|null, category:string, error:?string}
     */
    public static function ping(Client $client): array
    {
        $start = microtime(true);
        $resp = $client->get('/v5/market/time');

        $ok = $resp['category'] === Errors::SUCCESS;
        $timeMs = null;
        $driftMs = null;

        if ($ok && isset($resp['result']['timeNano'])) {
            $timeMs  = (int)floor((int)$resp['result']['timeNano'] / 1_000_000);
            $localMs = (int)round(microtime(true) * 1000);
            $driftMs = $timeMs - $localMs;
        } elseif ($ok && isset($resp['result']['timeSecond'])) {
            $timeMs  = (int)$resp['result']['timeSecond'] * 1000;
            $localMs = (int)round(microtime(true) * 1000);
            $driftMs = $timeMs - $localMs;
        }

        $duration = (int)round((microtime(true) - $start) * 1000);

        self::recordPing('public', $ok, $duration, $resp['error'] ?? $resp['ret_msg'] ?? null);

        return [
            'ok'       => $ok,
            'time_ms'  => $timeMs,
            'drift_ms' => $driftMs,
            'category' => $resp['category'],
            'error'    => $resp['error'] ?? $resp['ret_msg'] ?? null,
        ];
    }

    /**
     * Проверка подписи: вызвать приватный endpoint и убедиться, что Bybit принял подпись.
     * Используем /v5/account/wallet-balance (минимальные права).
     *
     * @return array{ok:bool, category:string, ret_code:?int, ret_msg:?string, error:?string}
     */
    public static function authCheck(Client $client): array
    {
        if (!$client->isAuthorized()) {
            self::recordPing('signed', false, 0, 'no_credentials');
            return [
                'ok'       => false,
                'category' => Errors::AUTH,
                'ret_code' => null,
                'ret_msg'  => 'API ключи не настроены',
                'error'    => 'no_credentials',
            ];
        }

        $start = microtime(true);
        // accountType=UNIFIED — для Unified Trading Account
        $resp = $client->getSigned('/v5/account/wallet-balance', ['accountType' => 'UNIFIED']);
        $duration = (int)round((microtime(true) - $start) * 1000);

        $ok = $resp['category'] === Errors::SUCCESS;
        self::recordPing('signed', $ok, $duration, $resp['error'] ?? $resp['ret_msg'] ?? null);

        return [
            'ok'       => $ok,
            'category' => $resp['category'],
            'ret_code' => $resp['ret_code'],
            'ret_msg'  => $resp['ret_msg'],
            'error'    => $resp['error'],
        ];
    }

    /**
     * Получить статус последнего успешного ping для /healthz.
     *
     * @return array{last_ok_ts:?string, age_sec:?int}
     */
    public static function lastSuccessful(): array
    {
        try {
            $row = Database::pdo()->query(
                "SELECT ts FROM bybit_health_pings
                 WHERE success = 1
                 ORDER BY id DESC LIMIT 1"
            )->fetch();
            if (!$row) {
                return ['last_ok_ts' => null, 'age_sec' => null];
            }
            $ts = (string)$row['ts'];
            $ageSec = time() - (int)strtotime($ts);
            return ['last_ok_ts' => $ts, 'age_sec' => $ageSec];
        } catch (\Throwable $e) {
            return ['last_ok_ts' => null, 'age_sec' => null];
        }
    }

    private static function recordPing(string $kind, bool $success, int $durationMs, ?string $error): void
    {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO bybit_health_pings (ts, kind, success, duration_ms, error)
                 VALUES (:ts, :k, :s, :d, :e)'
            );
            $stmt->execute([
                ':ts' => self::nowIso(),
                ':k'  => $kind,
                ':s'  => $success ? 1 : 0,
                ':d'  => $durationMs,
                ':e'  => $error,
            ]);
        } catch (\Throwable $e) {
            EventRecorder::event(EventRecorder::WARN, 'bybit_health_record_failed', null, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function nowIso(): string
    {
        $t = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\TH:i:s.', (int)$t) . $micro . 'Z';
    }
}
