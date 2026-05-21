<?php
/**
 * Диагностика: сверяем avg_price в БД с формулой §6.4 spec.md
 *   avg_price = P_real − (P_real × sign × p × 1.5 × market_coef) / 100
 *   SL_real   = P_real − (P_real × sign × p × 2.0 × market_coef) / 100
 *
 * Использование: php bin/diag_avg_40.php [trade_id]
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
\BybitBot\Core\Bootstrap::init(__DIR__ . '/..');

$pdo = \BybitBot\Core\Database::pdo();
$tid = isset($argv[1]) ? (int)$argv[1] : 40;

$t = $pdo->query("SELECT * FROM trades WHERE id = $tid")->fetch(\PDO::FETCH_ASSOC);
if (!$t) { fwrite(STDERR, "trade not found\n"); exit(1); }

$mc = (float)(\BybitBot\Core\Config::get('market_coef', 's1', 1.35));

$entry = $t['entry_real'] !== null ? (float)$t['entry_real'] : (float)$t['entry_ref'];
$p     = (float)($t['p_for_strategy_calc'] ?? $t['signal_target_pct']);
$sign  = ($t['side'] === 'long') ? 1.0 : -1.0;

$slCalc  = $entry - ($entry * $sign * $p * 2.0 * $mc) / 100.0;
$avgCalc = $entry - ($entry * $sign * $p * 1.5 * $mc) / 100.0;
$tpCalc  = $entry - ($entry * $sign * $p * 1.0) / 100.0; // §6.1 без market_coef

echo "─── Trade #{$tid} ({$t['symbol']} {$t['side']} {$t['mode']}) ───\n";
echo sprintf("entry_ref           = %.6f\n", (float)$t['entry_ref']);
echo sprintf("entry_real          = %s\n", $t['entry_real'] !== null ? sprintf('%.6f', (float)$t['entry_real']) : 'NULL');
echo sprintf("p_for_strategy_calc = %.4f\n", (float)($t['p_for_strategy_calc'] ?? 0));
echo sprintf("signal_target_pct   = %.4f\n", (float)($t['signal_target_pct'] ?? 0));
echo sprintf("market_coef (s1)    = %.4f\n", $mc);
echo "\n";
echo "Значения в БД vs расчёт по spec.md §6:\n";
echo sprintf("  SL_init     = %.6f   (TZ §6.1 от entry_ref %.6f при p=%.4f)\n", (float)$t['sl_init'], (float)$t['entry_ref'], $p);
echo sprintf("  SL_current  = %.6f   ← факт. в БД\n", (float)($t['sl_current'] ?? 0));
echo sprintf("  SL_calc     = %.6f   ← §6.2 от entry_real\n", $slCalc);
echo sprintf("  TP_init     = %.6f\n", (float)$t['tp_init']);
echo sprintf("  TP_calc     = %.6f   ← §6.1 от entry_real\n", $tpCalc);
echo sprintf("  avg_price   = %.6f   ← факт. в БД\n", (float)($t['avg_price'] ?? 0));
echo sprintf("  avg_calc    = %.6f   ← §6.4 от entry_real\n", $avgCalc);

// Если avg_price в БД заметно не совпадает с avg_calc — диагностика
$avgDB = (float)($t['avg_price'] ?? 0);
if ($avgDB > 0 && abs($avgDB - $avgCalc) / $avgCalc > 0.01) {
    $deltaPct = ($avgDB / $entry - 1.0) * 100.0 * $sign * -1; // на сколько % avg от entry в "плохую" сторону
    $impliedFactor = ($avgDB / $entry - 1.0) * 100.0 / ($sign * -1) / ($p * $mc);
    echo "\n!! РАСХОЖДЕНИЕ avg_price !!\n";
    echo sprintf("  avg_price в БД отстоит от entry на %.3f%% (в сторону SL)\n", abs(($avgDB/$entry-1.0)*100.0));
    echo sprintf("  Подразумеваемый множитель = %.3f (ожидаем 1.5)\n", $impliedFactor);
}

// Дополнительно: что лежит в orders с purpose='avg'
echo "\n─── Ордера усреднения ───\n";
$ords = $pdo->prepare("SELECT id, status, side, qty, trigger_price, placed_at, bybit_order_link_id FROM orders WHERE trade_id = :t AND purpose = 'avg' ORDER BY id");
$ords->execute([':t' => $tid]);
while ($o = $ords->fetch(\PDO::FETCH_ASSOC)) {
    echo sprintf(
        "  #%d %s %s qty=%s trigger=%.6f placed=%s link=%s\n",
        $o['id'], $o['status'], $o['side'], $o['qty'], (float)$o['trigger_price'], $o['placed_at'], $o['bybit_order_link_id']
    );
}

// События по trade — фильтруем по интересным kind
echo "\n─── События trade (setup + sl + trailing) ───\n";
$ev = $pdo->prepare(
    "SELECT ts, kind, payload_json FROM trade_events
     WHERE trade_id = :t
       AND (kind LIKE 'position_opened%' OR kind LIKE 'sl_%' OR kind LIKE 'trailing%'
            OR kind LIKE '%reset%' OR kind = 'conditional_restored')
     ORDER BY id ASC"
);
$ev->execute([':t' => $tid]);
while ($e = $ev->fetch(\PDO::FETCH_ASSOC)) {
    echo "  [{$e['ts']}] {$e['kind']}\n";
    $p = json_decode($e['payload_json'] ?? '', true);
    if (is_array($p)) foreach ($p as $k => $v) echo "    {$k} = " . (is_scalar($v) ? $v : json_encode($v)) . "\n";
}
