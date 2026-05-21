<?php
/**
 * Диагностика: сравнить positions.sl_price (что в БД) с фактическим SL на Bybit.
 *
 * Запуск: php bin/diag_trailing_sl.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Core/Bootstrap.php';
require $root . '/vendor/autoload.php';
\BybitBot\Core\Bootstrap::init($root);

$pdo = \BybitBot\Core\Database::pdo();

// v0.9.0-step4d: адаптер выбирается per-trade из trades.account_id (multi-account).
$pickAdapter = static function (?int $accId) {
    if ($accId !== null) {
        return \BybitBot\Exchange\AdapterFactory::forAccount($accId);
    }
    return \BybitBot\Exchange\AdapterFactory::forCurrentMode();
};

$rows = $pdo->query("
    SELECT t.id, t.symbol, t.side, t.status, t.mode, t.account_id, t.account_name,
           t.entry_real, t.sl_init, t.sl_current,
           t.trailing_pct, t.trailing_trigger, t.trailing_activated_at,
           p.last_price, p.sl_price, p.tp_price, p.qty as pos_qty
    FROM trades t
    JOIN positions p ON p.trade_id = t.id
    WHERE t.status IN ('OPEN','AVERAGED')
    ORDER BY t.id
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Нет открытых позиций.\n";
    exit(0);
}

echo "Сравнение БД vs Bybit:\n\n";

foreach ($rows as $r) {
    $accId = isset($r['account_id']) && $r['account_id'] !== null ? (int)$r['account_id'] : null;
    $accLabel = $accId !== null ? " acc=#{$accId}({$r['account_name']})" : '';
    echo "--- Trade {$r['id']} {$r['symbol']} {$r['side']} mode={$r['mode']}{$accLabel} ---\n";
    echo "  Entry:           {$r['entry_real']}\n";
    echo "  Last price:      {$r['last_price']}\n";
    echo "  Trailing %:      {$r['trailing_pct']}\n";
    echo "  Trailing trigger: {$r['trailing_trigger']}\n";
    echo "  Trailing active: " . ($r['trailing_activated_at'] ? "YES ({$r['trailing_activated_at']})" : "no") . "\n";
    echo "  БД sl_init:      {$r['sl_init']}\n";
    echo "  БД sl_current:   {$r['sl_current']}\n";
    echo "  БД pos.sl_price: {$r['sl_price']}  <-- то, что UI показывает как trailing_stop_price\n";

    // Что ожидаем от трейлинга, если он реально активен:
    if ($r['trailing_pct'] && $r['last_price']) {
        $pct = (float)$r['trailing_pct'] / 100.0;
        $last = (float)$r['last_price'];
        $expectedTrailingSL = $r['side'] === 'short'
            ? $last * (1 + $pct)
            : $last * (1 - $pct);
        echo "  Ожидаемый trailing SL (last ± " . ($pct*100) . "%): " . number_format($expectedTrailingSL, 6) . "\n";
    }

    // Запрос на Bybit: позиция и условные ордера.
    try {
        // v0.9.0-step4d: paper-сделки не имеют смысла для live-диагностики — пропускаем.
        if ((string)$r['mode'] === 'paper') {
            echo "  paper-сделка — Bybit запрос пропущен\n\n";
            continue;
        }
        $adapter = $pickAdapter($accId);
        $bbList = $adapter->getPositions($r['symbol']);
        $bbPos = null;
        foreach ($bbList as $p) {
            if (!empty($p['size']) && (float)$p['size'] > 0) { $bbPos = $p; break; }
        }
        if ($bbPos) {
            echo "  Bybit size:        " . ($bbPos['size'] ?? '-') . "\n";
            echo "  Bybit avgPrice:    " . ($bbPos['avgPrice'] ?? '-') . "\n";
            echo "  Bybit stopLoss:    " . ($bbPos['stopLoss'] ?? '-') . "\n";
            echo "  Bybit takeProfit:  " . ($bbPos['takeProfit'] ?? '-') . "\n";
            echo "  Bybit trailingStop:" . ($bbPos['trailingStop'] ?? '-') . "\n";
            echo "  Bybit activePrice: " . ($bbPos['activePrice'] ?? '-') . "\n";
        } else {
            echo "  Bybit: позиция не найдена (size=0)\n";
        }
    } catch (\Throwable $e) {
        echo "  ОШИБКА запроса к Bybit: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
