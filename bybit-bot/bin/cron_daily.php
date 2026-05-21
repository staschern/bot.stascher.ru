#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * cron_daily — ежедневная задача (00:00 UTC).
 *
 * Что делает (Этап 2):
 *  1. Обновление кеша инструментов Bybit (bybit_instruments) — раз в сутки достаточно.
 *  2. Перерезолв unresolved сигналов после обновления кеша.
 *  3. Cleanup старых сигналов (старше 30 дней) — кроме тех, по которым были сделки.
 *  4. Архивация api_calls и trade_events (старше 90 дней) — Этап 3+.
 *  5. Снимок депозита (deposit_snapshots) — Этап 3 (нужен реальный getWalletBalance).
 *
 * См. spec.md §3.2 + §8.
 */

use BybitBot\Bybit\Client as BybitClient;
use BybitBot\Bybit\MarketInfo;
use BybitBot\Core\Bootstrap;
use BybitBot\Core\Database;
use BybitBot\Core\CronGuard;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Lock;
use BybitBot\Core\Logger;
use BybitBot\Trade\EquitySnapshotsService;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Bootstrap.php';
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

$lock = new Lock($root . '/data/locks/cron_daily.lock');
if (!$lock->acquire()) {
    Logger::get()->info('cron_daily: предыдущий запуск ещё работает — выходим.');
    exit(0);
}

$slot  = CronGuard::slotDaily();
$guard = new CronGuard('daily', $slot);
if (!$guard->begin()) {
    Logger::get()->info("cron_daily: запуск для {$slot} уже был — выходим.");
    exit(0);
}

try {
    EventRecorder::event(EventRecorder::INFO, 'cron_daily_start', null, ['slot' => $slot]);

    $instrumentsCount = 0;
    $reresolved = ['resolved' => 0, 'still_unresolved' => 0];
    $signalsDeleted = 0;

    // ───────────────────────────────────────────────────────
    // 1. Обновление кеша инструментов
    // ───────────────────────────────────────────────────────
    try {
        $client = BybitClient::default();
        $instrumentsCount = MarketInfo::refresh($client);
        Logger::get()->info("cron_daily: обновлено инструментов: {$instrumentsCount}");
        EventRecorder::event(EventRecorder::INFO, 'bybit_instruments_refreshed', null, [
            'count' => $instrumentsCount,
        ]);
    } catch (\Throwable $e) {
        Logger::get()->warning('cron_daily: instruments refresh failed: ' . $e->getMessage());
        EventRecorder::event(EventRecorder::WARN, 'bybit_instruments_refresh_failed', null, [
            'error' => $e->getMessage(),
        ]);
    }

    // ───────────────────────────────────────────────────────
    // 2. Перерезолв unresolved сигналов
    // ───────────────────────────────────────────────────────
    try {
        $reresolved = MarketInfo::reresolveSignals();
        Logger::get()->info('cron_daily: signals reresolved', $reresolved);
        EventRecorder::event(EventRecorder::INFO, 'signals_reresolved', null, $reresolved);
    } catch (\Throwable $e) {
        Logger::get()->warning('cron_daily: reresolve failed: ' . $e->getMessage());
    }

    // ───────────────────────────────────────────────────────
    // 3. Cleanup старых сигналов (>30 дней)
    // На Этапе 2 в trades нет ссылки на signal_id, поэтому удаляем все старше 30 дней.
    // Этап 3 добавит signal_id в trades — тогда защитим использованные.
    // ───────────────────────────────────────────────────────
    try {
        $cutoffUtc = gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400);
        $stmt = Database::pdo()->prepare(
            'DELETE FROM signals WHERE saved_at_utc < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoffUtc]);
        $signalsDeleted = $stmt->rowCount();
        if ($signalsDeleted > 0) {
            Logger::get()->info("cron_daily: удалено старых сигналов: {$signalsDeleted}");
            EventRecorder::event(EventRecorder::INFO, 'signals_cleanup', null, [
                'deleted' => $signalsDeleted,
                'cutoff'  => $cutoffUtc,
            ]);
        }
    } catch (\Throwable $e) {
        Logger::get()->warning('cron_daily: signals cleanup failed: ' . $e->getMessage());
    }

    // ───────────────────────────────────────────────────────
    // 4. v0.9.0-step9 task4: equity snapshots (week + month)
    //    На каждый запуск создаём недостающие snapshot'ы для текущей недели/месяца.
    //    Идемпотентно: повторный вызов не перезаписывает существующие.
    // ───────────────────────────────────────────────────────
    $snapWritten = 0;
    $snapSkipped = 0;
    try {
        $snapRes = EquitySnapshotsService::snapshotDaily();
        $snapWritten = (int)($snapRes['written'] ?? 0);
        $snapSkipped = (int)($snapRes['skipped'] ?? 0);
        Logger::get()->info('cron_daily: equity_snapshots saved', [
            'written' => $snapWritten,
            'skipped' => $snapSkipped,
        ]);
        EventRecorder::event(EventRecorder::INFO, 'equity_snapshots_daily', null, [
            'written' => $snapWritten,
            'skipped' => $snapSkipped,
        ]);
    } catch (\Throwable $e) {
        Logger::get()->warning('cron_daily: equity_snapshots failed: ' . $e->getMessage());
        EventRecorder::event(EventRecorder::WARN, 'equity_snapshots_failed', null, [
            'error' => $e->getMessage(),
        ]);
    }

    $msg = sprintf(
        'instruments=%d resolved=%d still_unresolved=%d signals_deleted=%d snap_written=%d snap_skipped=%d',
        $instrumentsCount,
        $reresolved['resolved'],
        $reresolved['still_unresolved'],
        $signalsDeleted,
        $snapWritten,
        $snapSkipped
    );
    $guard->success($msg);
} catch (\Throwable $e) {
    Logger::get()->error('cron_daily fatal: ' . $e->getMessage());
    EventRecorder::event(EventRecorder::CRITICAL, 'cron_daily_fatal', null, ['error' => $e->getMessage()]);
    $guard->fail($e->getMessage());
    exit(1);
} finally {
    $lock->release();
}
