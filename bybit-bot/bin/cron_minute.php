#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * cron_minute — минутный скрипт сопровождения.
 *
 * Запускается каждую минуту.
 *
 * Что делает (Stage 3):
 *  1. Тик адаптера по каждому активному exchange: conditional → filled, SL/TP/trailing.
 *  2. Для каждого исполненного conditional → onPositionOpened().
 *  3. Для каждой позиции с filled avg → onPositionAveraged().
 *  4. monitorPendingConditionals: pre-fill cancel (SL прошёл) + fill-проверка.
 *  5. Отмена дочерних ордеров для closed позиций.
 *
 * Режим 'pause' не меняет поведение cron_minute — существующие ордера обслуживаются.
 *
 * См. spec.md §3.2 и §13a (v0.5.0).
 */

use BybitBot\Core\Bootstrap;
use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Config;
use BybitBot\Core\CronGuard;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Lock;
use BybitBot\Core\Logger;
use BybitBot\Exchange\AdapterFactory;
use BybitBot\Exchange\ExchangeAdapter;
use BybitBot\Exchange\PaperAdapter;
use BybitBot\Strategies\StrategyRegistry;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Bootstrap.php';
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

// v0.8.0.13: --manual — ручной запуск из UI.
// Ожидаем системный cron и используем уникальный слот, чтобы CronGuard пустил.
$manual = in_array('--manual', $argv ?? [], true);

$lock = new Lock($root . '/data/locks/cron_minute.lock');
if (!$lock->acquire($manual)) {
    // Системный cron работает сейчас и это не manual — выходим.
    exit(0);
}

$slot  = $manual
    ? CronGuard::slotMinute() . ':manual:' . sprintf('%.3f', microtime(true))
    : CronGuard::slotMinute();
$guard = new CronGuard('minute', $slot);
if (!$guard->begin()) {
    exit(0);
}

try {
    $mode = (string)Config::get('mode', null, 'paper');
    Logger::get()->debug("cron_minute: slot={$slot} mode={$mode}");

    // Режим pause НЕ останавливает cron_minute — существующие сделки обслуживаются.
    // Pause только запрещает collectAutoIntents в cron_hourly.

    runMinuteTick($root, $mode);

    $guard->success("mode={$mode}");

} catch (\Throwable $e) {
    Logger::get()->error('cron_minute fatal: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
    EventRecorder::event(EventRecorder::ERROR, 'cron_minute_fatal', null, ['error' => $e->getMessage()]);
    $guard->fail($e->getMessage());
    exit(1);
} finally {
    $lock->release();
}

// ────────────────────────────────────────────────────────────

/**
 * Основная логика минутного тика — обслуживает все активные exchange.
 */
function runMinuteTick(string $root, string $mode): void
{
    $pdo = Database::pdo();

    // ─────────────────────────────────────────────────────
    // Шаг 1: Определить активные exchange из orders/positions
    // ─────────────────────────────────────────────────────
    $activeExchanges = getActiveExchanges($pdo);

    // ─────────────────────────────────────────────────────
    // Шаг 2: Тик по каждому exchange
    // ─────────────────────────────────────────────────────
    // v0.9.0-step4: для paper — один адаптер; для testnet/live — поаккаунтный тик
    // (все enabled ℘ все аккаунты с открытыми сделками).
    foreach ($activeExchanges as $exchange) {
        if ($exchange === 'paper') {
            try {
                $adapter = AdapterFactory::forExchange('paper');
                $events  = $adapter->tick();
                if (!empty($events)) {
                    Logger::get()->info("cron_minute: paper tick events", ['count' => count($events)]);
                }
            } catch (\Throwable $e) {
                Logger::get()->error("cron_minute: tick failed for exchange=paper", [
                    'error' => $e->getMessage(),
                ]);
                EventRecorder::event(EventRecorder::ERROR, 'cron_minute_tick_failed', null, [
                    'exchange' => 'paper',
                    'error'    => $e->getMessage(),
                ]);
            }
            continue;
        }

        // testnet / live — итерируем по аккаунтам.
        $accountIds = collectAccountIdsForExchange($pdo, $exchange);
        if (empty($accountIds)) {
            Logger::get()->debug("cron_minute: нет аккаунтов для тика exchange={$exchange}");
            continue;
        }

        foreach ($accountIds as $accId) {
            try {
                $adapter = AdapterFactory::forAccount($accId);
                $events  = $adapter->tick();
                if (!empty($events)) {
                    Logger::get()->info("cron_minute: tick events", [
                        'exchange'   => $exchange,
                        'account_id' => $accId,
                        'count'      => count($events),
                    ]);
                }
            } catch (\Throwable $e) {
                Logger::get()->error("cron_minute: tick failed", [
                    'exchange'   => $exchange,
                    'account_id' => $accId,
                    'error'      => $e->getMessage(),
                ]);
                EventRecorder::event(EventRecorder::ERROR, 'cron_minute_tick_failed', null, [
                    'exchange'   => $exchange,
                    'account_id' => $accId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    // ─────────────────────────────────────────────────────
    // Шаг 3: Отмена stale-conditionals в paper (§5.5)
    // ─────────────────────────────────────────────────────
    if (in_array('paper', $activeExchanges, true) || $mode === 'paper') {
        $paperAdapter = PaperAdapter::default();
        $cancelled    = $paperAdapter->cancelStalePendingConditionals();
        if (!empty($cancelled)) {
            Logger::get()->info('cron_minute: отменены просроченные conditional', ['trade_ids' => $cancelled]);
        }
    }

    // ─────────────────────────────────────────────────────
    // Шаг 4: onPositionOpened / onPositionAveraged
    // ─────────────────────────────────────────────────────
    $strategiesConfig = require $root . '/config/strategies.php';
    $registry         = new StrategyRegistry($strategiesConfig);

    $newlyOpened = getNewlyOpenedTrades($pdo);
    foreach ($newlyOpened as $trade) {
        $exchange      = (string)($trade['mode'] ?? 'paper');
        $depositAnchor = loadMinuteDepositAnchor($exchange);
        // v0.9.0-step4: выбираем адаптер по account_id из trade (если есть), иначе legacy.
        try {
            $adapter = pickAdapterForTrade($trade);
        } catch (\Throwable $e) {
            Logger::get()->error("cron_minute: не удалось получить адаптер для trade #{$trade['id']}", [
                'error' => $e->getMessage(),
            ]);
            EventRecorder::tradeEvent((int)$trade['id'], EventRecorder::ERROR, 'pick_adapter_failed', [
                'error' => $e->getMessage(),
            ]);
            continue;
        }

        $context = [
            'adapter'        => $adapter,
            'deposit_anchor' => $depositAnchor,
            'mode'           => $exchange,
        ];

        $stratId = (string)$trade['strategy_id'];
        try {
            $strategy = $registry->get($stratId);
            $strategy->onPositionOpened($trade, $context);
        } catch (\Throwable $e) {
            Logger::get()->error("cron_minute: onPositionOpened failed для trade #{$trade['id']}", [
                'error' => $e->getMessage(),
            ]);
            EventRecorder::tradeEvent((int)$trade['id'], EventRecorder::ERROR, 'on_position_opened_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    $newlyAveraged = getNewlyAveragedTrades($pdo);
    foreach ($newlyAveraged as $trade) {
        $exchange      = (string)($trade['mode'] ?? 'paper');
        $depositAnchor = loadMinuteDepositAnchor($exchange);
        // v0.9.0-step4: адаптер по account_id (или legacy fallback).
        try {
            $adapter = pickAdapterForTrade($trade);
        } catch (\Throwable $e) {
            Logger::get()->error("cron_minute: не удалось получить адаптер для trade #{$trade['id']}", [
                'error' => $e->getMessage(),
            ]);
            EventRecorder::tradeEvent((int)$trade['id'], EventRecorder::ERROR, 'pick_adapter_failed', [
                'error' => $e->getMessage(),
            ]);
            continue;
        }

        $context = [
            'adapter'        => $adapter,
            'deposit_anchor' => $depositAnchor,
            'mode'           => $exchange,
        ];

        $stratId = (string)$trade['strategy_id'];
        try {
            $strategy = $registry->get($stratId);
            if (method_exists($strategy, 'onPositionAveraged')) {
                $strategy->onPositionAveraged($trade, $context);
            }
        } catch (\Throwable $e) {
            Logger::get()->error("cron_minute: onPositionAveraged failed для trade #{$trade['id']}", [
                'error' => $e->getMessage(),
            ]);
            EventRecorder::tradeEvent((int)$trade['id'], EventRecorder::ERROR, 'on_position_averaged_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────
    // Шаг 5: Pre-fill cancel для placed conditional ордеров
    // ─────────────────────────────────────────────────────
    monitorPendingConditionals($pdo);

    // ─────────────────────────────────────────────────────
    // Шаг 6: Закрытые позиции — отмена дочерних ордеров
    // ─────────────────────────────────────────────────────
    cancelOrphansForClosedTrades($pdo);
}

/**
 * Определить активные exchange по наличию незавершённых ордеров/позиций.
 *
 * @return string[]
 */
function getActiveExchanges(\PDO $pdo): array
{
    $exchanges = [];

    $stmt = $pdo->query(
        "SELECT DISTINCT exchange FROM orders
         WHERE status IN ('placed','pending') AND exchange IS NOT NULL"
    );
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $exch) {
        $exchanges[$exch] = true;
    }

    $stmt2 = $pdo->query(
        "SELECT DISTINCT exchange FROM positions
         WHERE closed_at IS NULL AND exchange IS NOT NULL"
    );
    foreach ($stmt2->fetchAll(\PDO::FETCH_COLUMN) as $exch) {
        $exchanges[$exch] = true;
    }

    // Всегда включаем paper для обратной совместимости
    $exchanges['paper'] = true;

    return array_keys($exchanges);
}

/**
 * Pre-fill cancel: проверить placed conditional ордера на условие досрочной отмены.
 *
 * Правила:
 *  - long: market_low <= sl_price → cancel (pre-fill cancel)
 *  - short: market_high >= sl_price → cancel
 *  - long: market_high >= trigger_price → fill (для paper)
 *  - short: market_low <= trigger_price → fill (для paper)
 */
function monitorPendingConditionals(\PDO $pdo): void
{
    $now = gmdate('Y-m-d\\TH:i:s.v\\Z');

    $orders = $pdo->query(
        "SELECT o.id, o.trade_id, o.side, o.trigger_price, o.sl_price, o.exchange,
                t.symbol, t.side as trade_side,
                t.ignore_sl_until_open, t.strategy_id, t.mode
         FROM orders o
         JOIN trades t ON t.id = o.trade_id
         WHERE o.status = 'placed' AND o.purpose = 'entry_conditional'"
    )->fetchAll();

    // v0.8.0.13: для RECOVERED отмена разрешена только при наличии парной позиции
    // в противоположную сторону по тому же strategy_id + mode + symbol (OPEN/AVERAGED).
    $pairStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM trades
         WHERE strategy_id = :sid AND mode = :mode AND symbol = :sym
           AND side = :opp_side
           AND status IN ('OPEN','AVERAGED')"
    );

    foreach ($orders as $order) {
        $symbol    = (string)$order['symbol'];
        $exchange  = (string)$order['exchange'];
        $side      = (string)$order['side']; // 'Buy'|'Sell'
        $tradeSide = (string)$order['trade_side']; // 'long'|'short'
        $trigger   = (float)$order['trigger_price'];
        $slPrice   = $order['sl_price'] !== null ? (float)$order['sl_price'] : null;
        $orderId   = (int)$order['id'];
        $tradeId   = (int)$order['trade_id'];
        $ignoreSl  = (int)($order['ignore_sl_until_open'] ?? 0);
        $stratId   = (string)($order['strategy_id'] ?? '');
        $tradeMode = (string)($order['mode'] ?? '');

        // v0.8.0.13: для RECOVERED — проверяем парную позицию.
        // Нет пары — пропускаем (сохраняем ордер); есть — отменяем немедленно.
        $forceCancelByPair = false;
        if ($ignoreSl === 1) {
            $oppSide = ($tradeSide === 'long') ? 'short' : 'long';
            $pairStmt->execute([
                ':sid'      => $stratId,
                ':mode'     => $tradeMode,
                ':sym'      => $symbol,
                ':opp_side' => $oppSide,
            ]);
            $pairCount = (int)$pairStmt->fetchColumn();
            if ($pairCount === 0) {
                continue; // Нет пары — RECOVERED остаётся.
            }
            $forceCancelByPair = true;
            EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'recovered_pair_detected', [
                'symbol'     => $symbol,
                'side'       => $tradeSide,
                'opp_side'   => $oppSide,
                'strategy'   => $stratId,
                'mode'       => $tradeMode,
                'pair_count' => $pairCount,
            ]);
        }

        // Получить текущий рынок (для RECOVERED с парой это не обязательно — отменяем всё равно).
        $tick = $forceCancelByPair ? null : getMarketTick($symbol, $exchange);
        if ($tick === null && !$forceCancelByPair) {
            continue;
        }
        $high = $tick['high'] ?? null;
        $low  = $tick['low']  ?? null;

        // Pre-fill cancel: SL прошёл ДО триггера (или RECOVERED с парной позицией)
        $shouldCancel = $forceCancelByPair;
        $cancelReason = $forceCancelByPair ? 'recovered_pair' : null;
        if (!$shouldCancel && $slPrice !== null && $high !== null && $low !== null) {
            if ($side === 'Buy' && $low <= $slPrice) {
                $shouldCancel = true; $cancelReason = 'pre_fill_sl';
            } elseif ($side === 'Sell' && $high >= $slPrice) {
                $shouldCancel = true; $cancelReason = 'pre_fill_sl';
            }
        }

        if ($shouldCancel) {
            $pdo->prepare(
                "UPDATE orders SET status = 'cancelled', cancelled_at = :now WHERE id = :id"
            )->execute([':now' => $now, ':id' => $orderId]);

            $pdo->prepare(
                "UPDATE paper_orders SET status = 'cancelled' WHERE trade_id = :tid AND status = 'pending'"
            )->execute([':tid' => $tradeId]);

            $pdo->prepare(
                "UPDATE trades SET status = 'CANCELLED' WHERE id = :id AND status = 'PENDING_CONDITIONAL'"
            )->execute([':id' => $tradeId]);

            $eventKind = ($cancelReason === 'recovered_pair')
                ? 'pending_cancel_recovered_pair'
                : 'pending_cancel_pre_fill_sl';
            EventRecorder::tradeEvent($tradeId, EventRecorder::WARN, $eventKind, [
                'symbol'      => $symbol,
                'exchange'    => $exchange,
                'side'        => $side,
                'sl_price'    => $slPrice,
                'market_low'  => $low,
                'market_high' => $high,
                'reason'      => $cancelReason,
            ]);

            Logger::get()->info("cron_minute: pre-fill cancel ({$cancelReason})", [
                'trade_id' => $tradeId,
                'symbol'   => $symbol,
                'sl_price' => $slPrice,
            ]);
            continue;
        }
    }
}

/**
 * Получить market tick (high/low за последнюю минуту).
 *
 * @return array{high:float, low:float}|null
 */
function getMarketTick(string $symbol, string $exchange): ?array
{
    if ($exchange === 'paper') {
        $adapter = PaperAdapter::default();
        return $adapter->getMarketTick($symbol);
    }

    // Для testnet/live — используем любой адаптер сети (public-эндпоинт kline
    // не требует ключей, но при multi-account берём первый enabled аккаунт;
    // если их нет — fallback на legacy forExchange).
    try {
        $adapter = pickAdapterForExchangePublic($exchange);
        $candles = $adapter->getKline($symbol, '1', 2);
        if (!empty($candles)) {
            return [
                'high' => (float)$candles[0]['high'],
                'low'  => (float)$candles[0]['low'],
            ];
        }
    } catch (\Throwable $e) {
        Logger::get()->warning("cron_minute: getMarketTick failed", [
            'symbol'   => $symbol,
            'exchange' => $exchange,
            'error'    => $e->getMessage(),
        ]);
    }
    return null;
}

/**
 * Получить trades со статусом OPEN у которых trailing_trigger IS NULL
 * (Strategy1::onPositionOpened ещё не запускался / не довёл дело до конца).
 *
 * v0.8.0.8: фильтр изменён с (sl_current IS NULL) на (trailing_trigger IS NULL).
 * Причина: после recovery шага C LiveReconciler sl_current уже заполнен
 * (его восстанавливает onConditionalFilled из расчётного SL сигнала), а вот
 * trailing_trigger ставится только Strategy1::onPositionOpened через
 * setTradingStop. Это и есть единственный надёжный признак "новизны".
 *
 * @return array
 */
function getNewlyOpenedTrades(\PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT t.* FROM trades t
         WHERE t.status = 'OPEN'
           AND t.trailing_trigger IS NULL
           AND t.entry_real IS NOT NULL
         LIMIT 20"
    );
    return $stmt->fetchAll();
}

/**
 * Получить trades где avg ордер исполнился, но break_even_price ещё не рассчитан.
 *
 * @return array
 */
function getNewlyAveragedTrades(\PDO $pdo): array
{
    // v0.8.0.6: расширен до (OPEN|AVERAGED). В paper адаптер сам всё считает
    // в onCronTick и в trades уже проставлен break_even_price. В live LiveReconciler
    // ставит status='AVERAGED' и averaged_at, но break_even_price остаётся null —
    // его должен рассчитать Strategy1::onPositionAveraged (он же вызовёт
    // adapter->setTradingStop для обновления SL/trailing на бирже).
    $stmt = $pdo->query(
        "SELECT t.* FROM trades t
         WHERE t.status IN ('OPEN','AVERAGED')
           AND t.averaged_at IS NOT NULL
           AND t.break_even_price IS NULL
         LIMIT 20"
    );
    return $stmt->fetchAll();
}

/**
 * Отменить дочерние pending ордера для уже закрытых trades.
 */
function cancelOrphansForClosedTrades(\PDO $pdo): void
{
    $now = gmdate('Y-m-d\\TH:i:s.v\\Z');

    $closedTrades = $pdo->query(
        "SELECT DISTINCT o.trade_id FROM orders o
         JOIN trades t ON t.id = o.trade_id
         WHERE t.status IN ('CLOSED_PROFIT', 'CLOSED_LOSS', 'CANCELLED')
           AND o.status IN ('placed','pending')
         LIMIT 50"
    )->fetchAll(\PDO::FETCH_COLUMN);

    foreach ($closedTrades as $tradeId) {
        $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', cancelled_at = :now
             WHERE trade_id = :tid AND status IN ('placed','pending')"
        )->execute([':tid' => (int)$tradeId, ':now' => $now]);

        $pdo->prepare(
            "UPDATE paper_orders SET status = 'cancelled' WHERE trade_id = :tid AND status = 'pending'"
        )->execute([':tid' => (int)$tradeId]);
    }
}

/**
 * v0.9.0-step4: выбрать адаптер для работы с конкретной сделкой:
 *  — paper → PaperAdapter::default();
 *  — если в trade есть account_id → AdapterFactory::forAccount($accId);
 *  — иначе → legacy AdapterFactory::forExchange($mode).
 *
 * После окончания перехода на multi-account путь "иначе" будет применяться
 * только к историческим trades с NULL account_id (до миграции).
 *
 * @param array<string,mixed> $trade Строка из trades.
 */
function pickAdapterForTrade(array $trade): ExchangeAdapter
{
    $mode = (string)($trade['mode'] ?? 'paper');
    if ($mode === 'paper') {
        return PaperAdapter::default();
    }
    $accountId = isset($trade['account_id']) && $trade['account_id'] !== null
        ? (int)$trade['account_id']
        : null;
    if ($accountId !== null) {
        return AdapterFactory::forAccount($accountId);
    }
    return AdapterFactory::forExchange($mode);
}

/**
 * v0.9.0-step4: собрать список account_id, для которых нужен тик на данной сети.
 *
 * Включаем:
 *  — все enabled+неархивные аккаунты сети (для своевременного реконсайла новых);
 *  — все аккаунты с открытыми trades этой сети (даже выключенные или archived —
 *    спецификация: “открытые сделки должны сопровождаться до конца”).
 *
 * @return int[]
 */
function collectAccountIdsForExchange(\PDO $pdo, string $exchange): array
{
    $ids = [];
    // 1) Enabled в bybit_accounts.
    foreach (BybitAccountsRepo::getEnabledForNetwork($exchange) as $acc) {
        $ids[(int)$acc['id']] = true;
    }
    // 2) Любые account_id с открытыми trades (включая disabled/archived).
    $stmt = $pdo->prepare(
        "SELECT DISTINCT account_id FROM trades
         WHERE mode = :m
           AND account_id IS NOT NULL
           AND status NOT IN ('CLOSED_PROFIT','CLOSED_LOSS','CANCELLED')"
    );
    $stmt->execute([':m' => $exchange]);
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $accId) {
        if ($accId !== null) {
            $ids[(int)$accId] = true;
        }
    }
    return array_keys($ids);
}

/**
 * v0.9.0-step4: выбрать адаптер для public-вызовов (kline/tickers) на сети testnet|live.
 * Берём первый из collectAccountIdsForExchange(); если нет ничего — legacy fallback.
 */
function pickAdapterForExchangePublic(string $exchange): ExchangeAdapter
{
    $ids = collectAccountIdsForExchange(Database::pdo(), $exchange);
    if (!empty($ids)) {
        try {
            return AdapterFactory::forAccount($ids[0]);
        } catch (\Throwable $e) {
            // Нет ключей у этого аккаунта — fall through.
        }
    }
    return AdapterFactory::forExchange($exchange);
}

/**
 * Загрузить deposit_anchor для заданного exchange/mode.
 */
function loadMinuteDepositAnchor(string $exchange): float
{
    $stmt = Database::pdo()->prepare(
        "SELECT value FROM deposit_snapshots WHERE mode = :m ORDER BY ts DESC LIMIT 1"
    );
    $stmt->execute([':m' => $exchange]);
    $val = $stmt->fetchColumn();
    if ($val !== false) {
        return (float)$val;
    }
    return (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
}
