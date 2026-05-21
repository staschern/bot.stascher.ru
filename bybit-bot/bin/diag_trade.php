<?php
/**
 * Универсальная диагностика сделки по id.
 * Использование: php bin/diag_trade.php <trade_id>
 * Печатает поля trades, trade_events (и вторым проходом — фильтр по SL/trail/ERROR),
 * ордера, и проверяет формулы §6.2/§6.4 против фактических sl_current/avg_price.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
\BybitBot\Core\Bootstrap::init(__DIR__ . '/..');

if (empty($argv[1])) {
    fwrite(STDERR, "Usage: php bin/diag_trade.php <trade_id>\n");
    exit(2);
}
$pdo = \BybitBot\Core\Database::pdo();
$tid = (int)$argv[1];

echo "─── trade #{$tid} ───\n\n";

$row = $pdo->prepare("SELECT * FROM trades WHERE id = :id");
$row->execute([':id' => $tid]);
$t = $row->fetch(\PDO::FETCH_ASSOC);

if (!$t) {
    echo "trade #{$tid} not found\n";
    exit(1);
}

echo "Поля trade:\n";
foreach ($t as $k => $v) {
    if ($v === null || $v === '') continue;
    printf("  %-30s = %s\n", $k, (string)$v);
}

echo "\n─── trade_events ───\n";
$ev = $pdo->prepare("SELECT id, ts, level, kind, payload_json FROM trade_events WHERE trade_id = :id ORDER BY id ASC");
$ev->execute([':id' => $tid]);
while ($r = $ev->fetch(\PDO::FETCH_ASSOC)) {
    echo "[{$r['id']}] {$r['ts']} {$r['level']} {$r['kind']}\n";
    if (!empty($r['payload_json'])) {
        $p = json_decode($r['payload_json'], true);
        if (is_array($p)) {
            foreach ($p as $pk => $pv) {
                $sv = is_scalar($pv) ? (string)$pv : json_encode($pv, JSON_UNESCAPED_UNICODE);
                if (strlen($sv) > 120) $sv = substr($sv, 0, 117) . '...';
                printf("      %-25s = %s\n", $pk, $sv);
            }
        }
    }
}

echo "\n─── orders ───\n";
// Сначала узнаём реальные колонки orders
$cols = $pdo->query('PRAGMA table_info(orders)')->fetchAll(\PDO::FETCH_ASSOC);
$colNames = array_map(static fn($c) => $c['name'], $cols);
echo "orders columns: " . implode(', ', $colNames) . "\n\n";
$ord = $pdo->prepare("SELECT * FROM orders WHERE trade_id = :id ORDER BY id");
$ord->execute([':id' => $tid]);
while ($r = $ord->fetch(\PDO::FETCH_ASSOC)) {
    foreach ($r as $k => $v) {
        if ($v === null || $v === '') continue;
        printf("  %-20s = %s\n", $k, (string)$v);
    }
    echo "  ----\n";
}

echo "\n─── Анализ ───\n";
$entry = (float)($t['entry_real'] ?? $t['entry_ref'] ?? 0);
$sl    = (float)($t['sl_current'] ?? 0);
$tp    = (float)($t['tp_init'] ?? 0);
$side  = (string)$t['side'];
$lev   = (int)($t['leverage'] ?? 1);

echo "entry={$entry} sl_current={$sl} tp_init={$tp} side={$side} lev={$lev}x\n";

if ($entry > 0 && $sl > 0) {
    $slPctRaw = ($side === 'short') ? (($sl - $entry) / $entry * 100) : (($entry - $sl) / $entry * 100);
    echo "SL distance from entry: " . number_format($slPctRaw, 3) . "% (raw) → " . number_format($slPctRaw * $lev, 2) . "% PnL\n";
}
if ($entry > 0 && $tp > 0) {
    $tpPctRaw = ($side === 'short') ? (($entry - $tp) / $entry * 100) : (($tp - $entry) / $entry * 100);
    echo "TP distance from entry: " . number_format($tpPctRaw, 3) . "% (raw) → " . number_format($tpPctRaw * $lev, 2) . "% PnL\n";
}

echo "\ntrailing_activated = " . var_export($t['trailing_activated'] ?? null, true) . "\n";
echo "trailing_high_water = " . var_export($t['trailing_high_water'] ?? null, true) . "\n";


echo "\n─── События setTradingStop и ошибки выставления SL ───\n";
$ev2 = $pdo->prepare("SELECT id, ts, level, kind, payload_json FROM trade_events
     WHERE trade_id = :id AND (kind LIKE '%trading_stop%' OR kind LIKE '%sl%' OR kind LIKE '%trail%' OR level = 'ERROR' OR level = 'WARN')
     ORDER BY id");
$ev2->execute([':id' => $tid]);
while ($r = $ev2->fetch(\PDO::FETCH_ASSOC)) {
    echo "[{$r['id']}] {$r['ts']} {$r['level']} {$r['kind']}\n";
    if (!empty($r['payload_json'])) {
        $p = json_decode($r['payload_json'], true);
        if (is_array($p)) {
            foreach ($p as $pk => $pv) {
                $sv = is_scalar($pv) ? (string)$pv : json_encode($pv, JSON_UNESCAPED_UNICODE);
                if (strlen($sv) > 160) $sv = substr($sv, 0, 157) . '...';
                printf("      %-25s = %s\n", $pk, $sv);
            }
        }
    }
}

echo "\n─── Проверка формулы §6.2 ───\n";
$p_calc = (float)($t['p_for_strategy_calc'] ?? 0);
$mc = 1.35;
$sign = ($side === 'long') ? 1.0 : -1.0;
$slRealCalc = $entry - ($entry * $sign * $p_calc * 2.0 * $mc) / 100.0;
$avgCalc    = $entry - ($entry * $sign * $p_calc * 1.5 * $mc) / 100.0;
echo "p_for_strategy_calc = {$p_calc}\n";
echo "SL_real_calc        = " . number_format($slRealCalc, 6) . " (в БД sl_current = {$sl})\n";
echo "avg_calc            = " . number_format($avgCalc, 6) . " (в БД avg_price  = {$t['avg_price']})\n";

if ($side === 'short' && $entry > 0) {
    $slDistPct = ($slRealCalc - $entry) / $entry * 100;
    echo "SL_real находится в " . number_format($slDistPct, 2) . "% НАД entry (для short это raw move). При плече {$lev}x = " . number_format($slDistPct * $lev, 1) . "% PnL.\n";
}

echo "\nГотово.\n";
