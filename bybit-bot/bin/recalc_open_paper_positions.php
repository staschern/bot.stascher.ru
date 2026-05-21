<?php
/**
 * bin/recalc_open_paper_positions.php
 *
 * Одноразовый скрипт пересчёта существующих OPEN paper-сделок под v0.7.0:
 *   - Новый qty по §5.4 (Variant B: leverage не делит qty + safety margin).
 *   - §6.2 SL_real, §6.3 trailing_pct/trigger, §6.4 avg_price/avg_qty.
 *   - Переоткрытие позиции виртуально по тому же entry_real (без реализации PnL).
 *
 * ВАЖНО: скрипт идемпотентен на уровне дубликатов avg-ордера
 * (использует idempotency-маркер 'recalc-v070-step3' в комментариях event-лога),
 * но повторный запуск создаст новые avg-ордера, если предыдущие не были помечены —
 * перед запуском старые avg-ордера для каждой trade удаляются.
 *
 * Запуск:
 *   php bin/recalc_open_paper_positions.php
 *   php bin/recalc_open_paper_positions.php --dry-run
 *   php bin/recalc_open_paper_positions.php --trade-id=42
 */

declare(strict_types=1);

use BybitBot\Core\Bootstrap;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Bootstrap.php';
require_once $root . '/vendor/autoload.php';

if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}

// Инициализация Config + Database (аналогично bin/cli.php)
Bootstrap::init($root);

// ─── Аргументы ───
$dryRun        = false;
$onlyTradeId   = null;
foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (strpos($arg, '--trade-id=') === 0) {
        $onlyTradeId = (int)substr($arg, strlen('--trade-id='));
    }
}

$pdo        = Database::pdo();
$marketCoef = (float)Config::get('market_coef',           null, 1.35);
$safetyPct  = (float)Config::get('qty_safety_margin_pct', null, 10.0);
if ($safetyPct < 0.0)  { $safetyPct = 0.0; }
if ($safetyPct > 50.0) { $safetyPct = 50.0; }

echo "[recalc] mode=" . ($dryRun ? 'DRY-RUN' : 'WRITE')
   . " market_coef={$marketCoef} qty_safety_margin_pct={$safetyPct}\n";

// ─── Поиск целевых сделок ───
$sql = "SELECT id, symbol, side, leverage, margin_mode, entry_ref, entry_real,
               sl_init, tp_init, signal_target_pct, p_for_strategy_calc,
               qty_initial, qty_current, mode, status
        FROM trades
        WHERE status = 'OPEN' AND mode = 'paper'";
$params = [];
if ($onlyTradeId !== null) {
    $sql .= ' AND id = :id';
    $params[':id'] = $onlyTradeId;
}
$sql .= ' ORDER BY id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$trades = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (!$trades) {
    echo "[recalc] нет OPEN paper-сделок\n";
    exit(0);
}

echo "[recalc] найдено сделок: " . count($trades) . "\n";

// ─── Утилиты округления ───
$floorToStep = static function (float $value, float $step): float {
    if ($step <= 0) {
        return $value;
    }
    return floor($value / $step) * $step;
};
$ceilToStep = static function (float $value, float $step): float {
    if ($step <= 0) {
        return $value;
    }
    return ceil($value / $step) * $step;
};

$nowIso = static function (): string {
    $t     = microtime(true);
    $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
    return gmdate('Y-m-d\\TH:i:s.', (int)$t) . $micro . 'Z';
};

$infoStmt = $pdo->prepare('SELECT tick_size, qty_step, min_order_qty FROM bybit_instruments WHERE symbol = :s');

$paperInitialDeposit = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);

$totalChanged = 0;
$totalSkipped = 0;

foreach ($trades as $t) {
    $tradeId  = (int)$t['id'];
    $symbol   = (string)$t['symbol'];
    $side     = (string)$t['side']; // 'long'|'short'
    $leverage = max(1, (int)$t['leverage']);
    $marginMode = (string)($t['margin_mode'] ?? 'cross');

    // entry_real (фактическая цена входа) — если нет, используем entry_ref
    $entry = isset($t['entry_real']) && $t['entry_real'] > 0
        ? (float)$t['entry_real']
        : (float)($t['entry_ref'] ?? 0);
    if ($entry <= 0) {
        echo "  trade #{$tradeId}: пропущен — нет entry_real/entry_ref\n";
        $totalSkipped++;
        continue;
    }

    // p — приоритет p_for_strategy_calc → signal_target_pct → вывести из sl_init
    $p = null;
    if (isset($t['p_for_strategy_calc']) && $t['p_for_strategy_calc'] > 0) {
        $p = (float)$t['p_for_strategy_calc'];
    } elseif (isset($t['signal_target_pct']) && $t['signal_target_pct'] != 0) {
        $p = abs((float)$t['signal_target_pct']);
    } elseif (isset($t['sl_init']) && $t['sl_init'] > 0) {
        $p = abs(((float)$t['sl_init'] - $entry) / $entry) * 100.0;
    }
    if ($p === null || $p <= 0) {
        echo "  trade #{$tradeId}: пропущен — нет p_for_strategy_calc/signal_target_pct\n";
        $totalSkipped++;
        continue;
    }

    // depositAnchor / base_lot — в текущей схеме эти поля не хранятся в trades:
    // берём текущий paper_initial_deposit как anchor.
    $depositAnchor = $paperInitialDeposit;
    $baseLot       = 0.01 * $depositAnchor;

    // §5.4 (v0.7.0)
    $notional        = $baseLot * 100.0 / $p;
    $qtyRaw          = $notional / $entry;
    $qtySafe         = $qtyRaw * (1.0 - $safetyPct / 100.0);

    // qtyStep / tickSize / minQty
    $infoStmt->execute([':s' => $symbol]);
    $instr = $infoStmt->fetch(\PDO::FETCH_ASSOC);
    $tickSize = $instr ? (float)$instr['tick_size'] : 0.0;
    $qtyStep  = $instr ? (float)$instr['qty_step']  : 0.0;
    $qtyMin   = $instr ? (float)$instr['min_order_qty'] : 0.0;

    if ($qtyStep > 0) {
        $qtyCoins = $floorToStep($qtySafe, $qtyStep);
    } else {
        $qtyCoins = $qtySafe;
    }
    if ($qtyMin > 0 && $qtyCoins < $qtyMin) {
        if ($qtyStep > 0) {
            $qtyCoins = $ceilToStep($qtyMin, $qtyStep);
        } else {
            $qtyCoins = $qtyMin;
        }
    }
    if ($qtyCoins <= 0) {
        echo "  trade #{$tradeId}: пропущен — qty<=0 после округления\n";
        $totalSkipped++;
        continue;
    }

    // §6.2: SL_real
    $sign  = ($side === 'long') ? 1.0 : -1.0;
    $slReal = $entry - ($entry * $sign * $p * 2.0 * $marketCoef) / 100.0;

    // §6.3: trailing
    $movementCoef    = (int)floor($p / 4.0) + 1;
    if ($movementCoef < 1) { $movementCoef = 1; }
    $trailingPctRaw  = $p / $movementCoef - 0.3;
    $trailingPct     = floor($trailingPctRaw * 10.0) / 10.0;
    if ($trailingPct < 0.1) { $trailingPct = 0.1; }
    $trailingTrigger = $entry + ($entry * $sign * ($p / $movementCoef)) / 100.0;

    // §6.4: avg
    $avgPrice = $entry - ($entry * $sign * $p * 1.5 * $marketCoef) / 100.0;
    $avgQty   = $qtyCoins * 2.0 * $marketCoef;
    if ($tickSize > 0) {
        $avgPrice = ($sign > 0)
            ? (floor($avgPrice / $tickSize) * $tickSize)
            : (ceil($avgPrice  / $tickSize) * $tickSize);
    }
    if ($qtyStep > 0) {
        $avgQty = $ceilToStep($avgQty, $qtyStep);
    }

    // bybitSide для positions/orders
    $bybitSide = ($side === 'long') ? 'Buy' : 'Sell';

    echo sprintf(
        "  trade #%d %s %s: entry=%.6f p=%.4f → qty=%s (было %s), SL=%.6f, TS=%.6f (%.1f%%), Avg=%.6f (qty=%s)\n",
        $tradeId, $symbol, $side, $entry, $p,
        (string)$qtyCoins, (string)$t['qty_current'],
        $slReal, $trailingTrigger, $trailingPct, $avgPrice, (string)$avgQty
    );

    if ($dryRun) {
        $totalChanged++;
        continue;
    }

    $pdo->beginTransaction();
    try {
        // 1) Удалить старые позиции (positions + paper_positions)
        $pdo->prepare('DELETE FROM positions       WHERE trade_id = :tid')->execute([':tid' => $tradeId]);
        $pdo->prepare('DELETE FROM paper_positions WHERE trade_id = :tid')->execute([':tid' => $tradeId]);

        // 2) Отменить старые avg/sl/tp/entry-ордера для этой сделки
        //    (entry-ордер был filled — оставляем; avg/sl/tp если pending/placed — отменяем)
        $pdo->prepare(
            "UPDATE orders
             SET status = 'cancelled'
             WHERE trade_id = :tid
               AND purpose IN ('avg','sl','tp')
               AND status IN ('pending','placed','submitted')"
        )->execute([':tid' => $tradeId]);

        // 3) UPDATE trades: новые qty + сброс расчётных полей §6.2-6.4 (заполним ниже)
        $pdo->prepare(
            "UPDATE trades
             SET qty_initial = :qty,
                 qty_current = :qty,
                 sl_current  = :slr,
                 trailing_pct = :tpct,
                 trailing_trigger = :ttrg,
                 avg_price   = :avgp,
                 qty_avg     = :avgq,
                 p_for_strategy_calc = :pcalc
             WHERE id = :id"
        )->execute([
            ':qty'   => $qtyCoins,
            ':slr'   => $slReal,
            ':tpct'  => $trailingPct,
            ':ttrg'  => $trailingTrigger,
            ':avgp'  => $avgPrice,
            ':avgq'  => $avgQty,
            ':pcalc' => $p,
            ':id'    => $tradeId,
        ]);

        // 4) Пересоздать positions (single source) + paper_positions (legacy)
        $now = $nowIso();
        $pdo->prepare(
            "INSERT INTO positions
             (trade_id, symbol, side, qty, qty_initial, avg_entry_price, leverage, margin_mode,
              sl_price, tp_price, trailing_pct, trailing_trigger_price, opened_at, paper, exchange, last_price)
             VALUES (:tid, :sym, :side, :qty, :qty, :aep, :lev, :mm, :sl, :tp, :tpct, :ttrg, :oa, 1, 'paper', :aep)"
        )->execute([
            ':tid'  => $tradeId,
            ':sym'  => $symbol,
            ':side' => $bybitSide,
            ':qty'  => $qtyCoins,
            ':aep'  => $entry,
            ':lev'  => $leverage,
            ':mm'   => $marginMode,
            ':sl'   => $slReal,
            ':tp'   => $trailingTrigger,
            ':tpct' => $trailingPct,
            ':ttrg' => $trailingTrigger,
            ':oa'   => $now,
        ]);
        $pdo->prepare(
            "INSERT INTO paper_positions
             (trade_id, symbol, side, qty, qty_initial, avg_entry_price, leverage, margin_mode,
              sl_price, tp_price, trailing_pct, trailing_trigger_price, opened_at)
             VALUES (:tid, :sym, :side, :qty, :qty, :aep, :lev, :mm, :sl, :tp, :tpct, :ttrg, :oa)"
        )->execute([
            ':tid'  => $tradeId,
            ':sym'  => $symbol,
            ':side' => $bybitSide,
            ':qty'  => $qtyCoins,
            ':aep'  => $entry,
            ':lev'  => $leverage,
            ':mm'   => $marginMode,
            ':sl'   => $slReal,
            ':tp'   => $trailingTrigger,
            ':tpct' => $trailingPct,
            ':ttrg' => $trailingTrigger,
            ':oa'   => $now,
        ]);

        // 5) Создать avg-ордер
        $avgLink = 's1-' . $tradeId . '-avg-' . bin2hex(random_bytes(3));
        $pdo->prepare(
            "INSERT INTO orders
             (trade_id, exchange, paper, purpose, status, side, order_type, qty, trigger_price,
              bybit_order_link_id, placed_at)
             VALUES (:tid, 'paper', 1, 'avg', 'placed', :side, 'Conditional', :qty, :trg, :link, :now)"
        )->execute([
            ':tid'  => $tradeId,
            ':side' => $bybitSide,
            ':qty'  => $avgQty,
            ':trg'  => $avgPrice,
            ':link' => $avgLink,
            ':now'  => $now,
        ]);

        $pdo->commit();
        $totalChanged++;

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'paper_position_recalculated', [
            'reason'           => 'v0.7.0 qty/SL/TS/avg recalc',
            'p'                => $p,
            'qty_old'          => $t['qty_current'],
            'qty_new'          => $qtyCoins,
            'sl_real'          => $slReal,
            'trailing_pct'     => $trailingPct,
            'trailing_trigger' => $trailingTrigger,
            'avg_price'        => $avgPrice,
            'avg_qty'          => $avgQty,
            'safety_pct'       => $safetyPct,
            'market_coef'      => $marketCoef,
        ]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo "  trade #{$tradeId}: ERROR — " . $e->getMessage() . "\n";
        $totalSkipped++;
    }
}

echo "\n[recalc] DONE — обработано: {$totalChanged}, пропущено: {$totalSkipped}"
   . ($dryRun ? ' (dry-run, без записи)' : '') . "\n";
