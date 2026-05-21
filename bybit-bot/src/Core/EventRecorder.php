<?php
declare(strict_types=1);

namespace BybitBot\Core;

/**
 * Запись событий в БД (`events` и `trade_events`). См. spec.md §12.
 *
 * Уровни: INFO | WARN | ERROR | CRITICAL.
 * Каждое событие также пробрасывается в файловый Logger.
 */
final class EventRecorder
{
    public const INFO     = 'INFO';
    public const WARN     = 'WARN';
    public const ERROR    = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    /**
     * @param array|object|null $payload
     */
    public static function event(
        string $level,
        string $kind,
        ?string $symbol = null,
        $payload = null
    ): void {
        $params = [
            ':ts'  => self::now(),
            ':lvl' => $level,
            ':k'   => $kind,
            ':sym' => $symbol,
            ':p'   => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
        // v0.8.0.17: ретраи на блокировку SQLite (busy_timeout в 5с не всегда хватает при
        // конкуренции с cron'ом, см. трейд #62).
        self::executeWithRetry(
            'INSERT INTO events (ts, level, kind, symbol, payload_json) VALUES (:ts, :lvl, :k, :sym, :p)',
            $params
        );

        self::logToFile($level, "[{$kind}]" . ($symbol ? " {$symbol}" : ''), $payload);
    }

    /**
     * @param array|object|null $payload
     */
    public static function tradeEvent(
        int $tradeId,
        string $level,
        string $kind,
        $payload = null
    ): void {
        $params = [
            ':t'   => $tradeId,
            ':ts'  => self::now(),
            ':lvl' => $level,
            ':k'   => $kind,
            ':p'   => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
        self::executeWithRetry(
            'INSERT INTO trade_events (trade_id, ts, level, kind, payload_json) VALUES (:t, :ts, :lvl, :k, :p)',
            $params
        );

        self::logToFile($level, "[trade#{$tradeId}/{$kind}]", $payload);
    }

    /**
     * v0.8.0.17: выполнить INSERT с ретраями на "database is locked".
     * 5 попыток с экспоненциальным sleep 50мс..800мс. События НИКОГДА не должны
     * фейлить основной поток из-за временной блокировки БД.
     */
    private static function executeWithRetry(string $sql, array $params): void
    {
        $maxAttempts = 5;
        $delayUs     = 50000; // 50 мс
        $lastErr     = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $stmt = Database::pdo()->prepare($sql);
                $stmt->execute($params);
                return;
            } catch (\PDOException $e) {
                $lastErr = $e;
                $msg = $e->getMessage();
                $isLocked = (stripos($msg, 'database is locked') !== false)
                         || (stripos($msg, 'database table is locked') !== false);
                if (!$isLocked || $attempt === $maxAttempts) {
                    // Нет смысла ломать основной поток из-за лога.
                    Logger::get()->error('event_recorder: insert failed', [
                        'sql'       => $sql,
                        'attempts'  => $attempt,
                        'err'       => $msg,
                    ]);
                    return;
                }
                usleep($delayUs);
                $delayUs = min($delayUs * 2, 800000);
            }
        }
    }

    private static function now(): string
    {
        $t = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\TH:i:s.', (int)$t) . $micro . 'Z';
    }

    /**
     * @param array|object|null $payload
     */
    private static function logToFile(string $level, string $msg, $payload): void
    {
        $logger = Logger::get();
        $context = $payload === null ? [] : (is_array($payload) ? $payload : ['payload' => $payload]);
        switch ($level) {
            case self::CRITICAL:
                $logger->critical($msg, $context);
                break;
            case self::ERROR:
                $logger->error($msg, $context);
                break;
            case self::WARN:
                $logger->warning($msg, $context);
                break;
            default:
                $logger->info($msg, $context);
                break;
        }
    }
}
