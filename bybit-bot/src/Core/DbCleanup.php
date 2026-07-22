<?php
declare(strict_types=1);

namespace BybitBot\Core;

/**
 * Периодическая очистка быстро растущих таблиц SQLite.
 *
 * Политика хранения:
 *   api_calls          — 14 дней  (логи каждого API-вызова, самый тяжёлый рост)
 *   events             — 90 дней  (глобальные события; вкладка «События»)
 *   cron_runs          — 60 дней  (anti-rerun guard; старые строки бесполезны)
 *   trade_events       — 90 дней  для ЗАКРЫТЫХ/CANCELLED сделок
 *                        (для открытых — хранятся бессрочно, их мало)
 *   bybit_health_pings — 7 дней   (ping каждый час, нужен только свежий)
 *   auth_attempts      — 7 дней   (brute-force защита; старые неактуальны)
 *   signals            — уже чистится в cron_daily (30 дней)
 *
 * Таблицы, которые не трогаем:
 *   trades, orders, positions, trade_funding_log — вся история сделок
 *   equity_snapshots, deposit_snapshots         — статистика за все периоды
 *   bybit_instruments, symbol_aliases           — справочники
 *   settings, strategy_settings, strategies     — конфигурация
 *   auth_users, auth_sessions                   — авторизация
 */
final class DbCleanup
{
    /**
     * Выполнить очистку и вернуть статистику (ключ → удалено строк).
     *
     * @return array{deleted: array<string,int>, vacuum: bool, error: ?string}
     */
    public static function run(): array
    {
        $pdo     = Database::pdo();
        $deleted = [];
        $error   = null;

        $cuts = [
            'api_calls'          => '-14 days',
            'events'             => '-90 days',
            'cron_runs'          => '-60 days',
            'bybit_health_pings' => '-7 days',
            'auth_attempts'      => '-7 days',
        ];

        // Простые таблицы — удалить всё старше cutoff
        $tsCol = [
            'api_calls'          => 'ts',
            'events'             => 'ts',
            'cron_runs'          => 'started_at',
            'bybit_health_pings' => 'ts',
            'auth_attempts'      => 'ts',
        ];

        foreach ($cuts as $table => $offset) {
            try {
                $cutoff = gmdate('Y-m-d\TH:i:s\Z', strtotime("now {$offset}"));
                $col    = $tsCol[$table];
                $stmt   = $pdo->prepare("DELETE FROM {$table} WHERE {$col} < :c");
                $stmt->execute([':c' => $cutoff]);
                $deleted[$table] = $stmt->rowCount();
            } catch (\Throwable $e) {
                Logger::get()->warning("DbCleanup: {$table} failed: " . $e->getMessage());
                $deleted[$table] = -1;
            }
        }

        // trade_events: только для давно закрытых/отменённых сделок
        try {
            $cutoff90 = gmdate('Y-m-d\TH:i:s\Z', strtotime('now -90 days'));
            $stmt = $pdo->prepare("
                DELETE FROM trade_events
                WHERE ts < :c
                  AND trade_id IN (
                      SELECT id FROM trades
                      WHERE status IN ('CLOSED_PROFIT','CLOSED_LOSS','CANCELLED')
                        AND closed_at < :c2
                  )
            ");
            $stmt->execute([':c' => $cutoff90, ':c2' => $cutoff90]);
            $deleted['trade_events'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            Logger::get()->warning('DbCleanup: trade_events failed: ' . $e->getMessage());
            $deleted['trade_events'] = -1;
        }

        // VACUUM — возвращает страницы SQLite в файловую систему.
        // Выполняем всегда: на пустой/маленькой БД занимает < 1 с,
        // на большой — несколько секунд. В cron_daily это допустимо.
        $vacuum = false;
        try {
            $pdo->exec('VACUUM');
            $vacuum = true;
        } catch (\Throwable $e) {
            Logger::get()->warning('DbCleanup: VACUUM failed: ' . $e->getMessage());
            $error = 'vacuum: ' . $e->getMessage();
        }

        $totalDeleted = array_sum(array_filter($deleted, fn($v) => $v > 0));
        Logger::get()->info('DbCleanup: done', array_merge($deleted, [
            'vacuum'  => $vacuum,
            'total'   => $totalDeleted,
        ]));

        return [
            'deleted' => $deleted,
            'vacuum'  => $vacuum,
            'error'   => $error,
        ];
    }

    /**
     * Краткая сводка для отображения в UI.
     *
     * @param array{deleted: array<string,int>, vacuum: bool, error: ?string} $result
     */
    public static function summary(array $result): string
    {
        $parts = [];
        foreach ($result['deleted'] as $table => $n) {
            if ($n > 0) {
                $parts[] = "{$table}:{$n}";
            }
        }
        $vacuum = $result['vacuum'] ? ' VACUUM OK' : '';
        return empty($parts) ? "нечего удалять{$vacuum}" : implode(' ', $parts) . $vacuum;
    }
}
