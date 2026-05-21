<?php
/**
 * Принудительный пересчёт SL_real по §6.2 для уже открытой сделки + выставление
 * на бирже через setTradingStop. Применяется к трейдам, у которых
 * onPositionOpened не довыставил sl_real (silent failure в setTradingStop —
 * например, трейд #63 BUSDT в v0.8.0.14 и раньше).
 *
 * Использование:
 *   php bin/force_recalc_sl.php <trade_id>          # dry-run
 *   php bin/force_recalc_sl.php <trade_id> --apply  # реально выставить SL
 *
 * Что делает:
 *   - Загружает trades по id (должен быть OPEN/AVERAGED)
 *   - Считает SL_real, trailing_pct, trailing_trigger, avg_price, avg_qty по §6.2/§6.3/§6.4
 *     из entry_real и p_for_strategy_calc
 *   - Печатает план изменений (current vs target по sl_current/trailing/avg_*)
 *   - При --apply: UPDATE trades + adapter->setTradingStop с sl_price и clear_tp
 *   - Записывает trade_event force_recalc_sl_applied (или _failed)
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
\BybitBot\Core\Bootstrap::init(__DIR__ . '/..');

use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Config;
use BybitBot\Exchange\AdapterFactory;
use BybitBot\Core\Rounding;

if (empty($argv[1])) {
    fwrite(STDERR, "Usage: php bin/force_recalc_sl.php <trade_id> [--apply]\n");
    exit(2);
}
$tradeId = (int)$argv[1];
$apply   = in_array('--apply', $argv, true);

$pdo = Database::pdo();
$stmt = $pdo->prepare("SELECT * FROM trades WHERE id = :id");
$stmt->execute([':id' => $tradeId]);
$t = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$t) {
    fwrite(STDERR, "trade #{$tradeId} не найден\n");
    exit(1);
}

$status = (string)($t['status'] ?? '');
if (!in_array($status, ['OPEN', 'AVERAGED'], true)) {
    fwrite(STDERR, "trade #{$tradeId} имеет статус {$status} — пересчёт SL имеет смысл только для OPEN/AVERAGED\n");
    exit(1);
}
if ($status === 'AVERAGED') {
    fwrite(STDERR, "ВНИМАНИЕ: трейд уже усреднён — SL должен считаться по §7.3, а не §6.2.\n");
    fwrite(STDERR, "Этот скрипт пересчитывает только по §6.2 (открытие). Прерывание.\n");
    exit(1);
}

$symbol  = (string)$t['symbol'];
$side    = (string)$t['side'];
$isLong  = ($side === 'long');
$sign    = $isLong ? 1.0 : -1.0;
$pReal   = (float)($t['entry_real'] ?? 0);
$p       = (float)($t['p_for_strategy_calc'] ?? 0);
$qty     = (float)($t['qty_current'] ?? $t['qty_initial'] ?? 0);
// v0.9.0-step4d: в таблице trades нет колонки 'exchange' — это был баг.
// Режим биржи хранится в trades.mode (paper/testnet/live).
$exchange = (string)($t['mode'] ?? 'live');
$accId    = isset($t['account_id']) && $t['account_id'] !== null ? (int)$t['account_id'] : null;

if ($pReal <= 0 || $p <= 0 || $qty <= 0) {
    fwrite(STDERR, "Недостаточно данных: entry_real={$pReal} p={$p} qty={$qty}\n");
    exit(1);
}

$marketCoef = (float)Config::get('market_coef', 's1', 1.35);

// v0.9.0-step4d: выбор адаптера multi-account.
// Для testnet/live берём адаптер конкретного аккаунта из trades.account_id.
// Для paper (account_id NULL) — обычный forExchange.
if (($exchange === 'testnet' || $exchange === 'live') && $accId !== null) {
    $adapter = AdapterFactory::forAccount($accId);
} else {
    $adapter = AdapterFactory::forExchange($exchange);
}
$instrInfo = $adapter->getInstrumentInfo($symbol);
$tickSize  = (float)$instrInfo['tickSize'];
$qtyStep   = (float)$instrInfo['qtyStep'];
$qtyMin    = (float)$instrInfo['qtyMin'];

// §6.2 SL_real
$slRealRaw = $pReal - ($pReal * $sign * $p * 2.0 * $marketCoef) / 100.0;
$slReal    = $isLong
    ? Rounding::roundToStep($slRealRaw, $tickSize, Rounding::UP)
    : Rounding::roundToStep($slRealRaw, $tickSize, Rounding::DOWN);

// §6.3 trailing — movement_coef по risk_mode аккаунта (v0.9.1)
$riskMode = BybitAccountsRepo::RISK_CONSERVATIVE;
if ($accId !== null) {
    $accRow = BybitAccountsRepo::find($accId);
    if ($accRow !== null) {
        $riskMode = (string)($accRow['risk_mode'] ?? BybitAccountsRepo::RISK_CONSERVATIVE);
    }
}
if ($riskMode === BybitAccountsRepo::RISK_STANDARD) {
    $movementCoef = (int)floor($p / 4.0) + 1;
    if ($movementCoef < 1) { $movementCoef = 1; }
} else {
    $movementCoef = 1;
    if ($p >= 2.0) {
        $k = 0;
        while (pow(2.0, $k) < $p) { $k++; }
        $movementCoef = (int)pow(2, $k);
    }
}
$trailingPct     = max(0.1, Rounding::floorToTenth($p / (float)$movementCoef - 0.3));
$triggerRaw      = $pReal + ($pReal * $sign * ($p / (float)$movementCoef)) / 100.0;
$triggerPrice    = $isLong
    ? Rounding::roundToStep($triggerRaw, $tickSize, Rounding::DOWN)
    : Rounding::roundToStep($triggerRaw, $tickSize, Rounding::UP);

// §6.4 avg
$avgRaw     = $pReal - ($pReal * $sign * $p * 1.5 * $marketCoef) / 100.0;
$avgPrice   = $isLong
    ? Rounding::roundToStep($avgRaw, $tickSize, Rounding::DOWN)
    : Rounding::roundToStep($avgRaw, $tickSize, Rounding::UP);
$avgQtyRaw  = $qty * 2.0 * $marketCoef;
$avgQty     = Rounding::roundToStep($avgQtyRaw, $qtyStep, Rounding::UP);
if ($avgQty < $qtyMin) {
    $avgQty = Rounding::roundToStep($qtyMin, $qtyStep, Rounding::UP);
}

$slCur     = (float)($t['sl_current'] ?? 0);
$tpctCur   = (float)($t['trailing_pct'] ?? 0);
$ttrigCur  = (float)($t['trailing_trigger'] ?? 0);
$avgPrCur  = (float)($t['avg_price'] ?? 0);
$avgQCur   = (float)($t['qty_avg'] ?? 0);

$accLabel = $accId !== null ? " acc=#{$accId}" : '';
echo "─── trade #{$tradeId} {$symbol} {$side} (status={$status}, ex={$exchange}{$accLabel}) ───\n";
echo "entry_real={$pReal} p={$p} qty={$qty} market_coef={$marketCoef} risk_mode={$riskMode} movement_coef={$movementCoef}\n\n";
echo "Field              current → target\n";
printf("  sl_current       %-12s → %s\n", $slCur, $slReal);
printf("  trailing_pct     %-12s → %s\n", $tpctCur, $trailingPct);
printf("  trailing_trigger %-12s → %s\n", $ttrigCur, $triggerPrice);
printf("  avg_price        %-12s → %s\n", $avgPrCur, $avgPrice);
printf("  qty_avg          %-12s → %s\n", $avgQCur, $avgQty);

if (!$apply) {
    echo "\nDry-run. Для применения добавьте флаг --apply\n";
    exit(0);
}

echo "\nПрименяем…\n";

// 1) UPDATE trades — с ретраем на database is locked
$updateTrades = function () use ($pdo, $slReal, $trailingPct, $triggerPrice, $avgPrice, $avgQty, $tradeId) {
    $maxAttempts = 5; $delayUs = 50000;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $pdo->prepare(
                'UPDATE trades SET sl_current = :sl, trailing_pct = :tpct, trailing_trigger = :ttrig,
                                   avg_price = :ap, qty_avg = :aq
                 WHERE id = :id'
            )->execute([
                ':sl' => $slReal, ':tpct' => $trailingPct, ':ttrig' => $triggerPrice,
                ':ap' => $avgPrice, ':aq' => $avgQty, ':id' => $tradeId,
            ]);
            return;
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'database is locked') === false || $attempt === $maxAttempts) throw $e;
            usleep($delayUs); $delayUs = min($delayUs * 2, 800000);
        }
    }
};
$updateTrades();
echo "  trades обновлены\n";

// 2) setTradingStop на бирже
$bybitSide = $isLong ? 'Buy' : 'Sell';
$ok = $adapter->setTradingStop($symbol, $bybitSide, [
    'sl_price'              => $slReal,
    'trailing_pct'          => $trailingPct,
    'trailing_trigger_price'=> $triggerPrice,
    'clear_tp'              => true,
    'trade_id'              => $tradeId,
    'context'               => [
        'purpose'      => 'force_recalc_sl',
        'entry_real'   => $pReal,
        'p'            => $p,
        'market_coef'  => $marketCoef,
        'script'       => 'bin/force_recalc_sl.php',
    ],
]);

if ($ok) {
    EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'force_recalc_sl_applied', [
        'sl_real'        => $slReal,
        'trailing_pct'   => $trailingPct,
        'trigger_price'  => $triggerPrice,
        'avg_price'      => $avgPrice,
        'avg_qty'        => $avgQty,
        'risk_mode'      => $riskMode,
        'movement_coef'  => $movementCoef,
    ]);
    echo "  SL принят биржей: {$slReal}\n";
} else {
    EventRecorder::tradeEvent($tradeId, EventRecorder::ERROR, 'force_recalc_sl_failed', [
        'sl_real'        => $slReal,
        'note'           => 'setTradingStop вернул false; смотрите bybit_trading_stop_failed',
    ]);
    echo "  SL НЕ принят биржей. Проверьте trade_events kind=bybit_trading_stop_failed\n";
}
