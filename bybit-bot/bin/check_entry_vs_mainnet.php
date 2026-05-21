<?php
/**
 * Диагностика: сравнить entry_ref последних paper-трейдов с реальной mainnet-ценой Bybit.
 * Запускать: sudo -u www-root php bin/check_entry_vs_mainnet.php
 *
 * Ходит на api.bybit.com напрямую (public, без подписи).
 */
declare(strict_types=1);

use BybitBot\Core\Bootstrap;
use BybitBot\Core\Database;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Bootstrap.php';
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

function fetchKline24(string $symbol): ?array
{
    $url = "https://api.bybit.com/v5/market/kline?category=linear&symbol={$symbol}&interval=60&limit=24";
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    $j = json_decode($body, true);
    if (!is_array($j) || ($j['retCode'] ?? -1) !== 0) {
        return null;
    }
    return $j['result']['list'] ?? null;
}

function analyze(string $symbol): array
{
    $rows = fetchKline24($symbol);
    if ($rows === null) {
        return ['ok' => false];
    }
    $highs = $lows = [];
    foreach ($rows as $r) {
        $highs[] = (float)$r[2];
        $lows[]  = (float)$r[3];
    }
    $maxH = max($highs);
    $minL = min($lows);
    $amp  = ($maxH - $minL) / $maxH * 100.0;
    $deltaPct = 0.08 * $amp;
    // newest 2 candles -> refPrice short = min(c0.low, c1.low), long = max(c0.high, c1.high)
    $refShort = min($lows[0], $lows[1]);
    $refLong  = max($highs[0], $highs[1]);
    return [
        'ok'        => true,
        'maxH'      => $maxH,
        'minL'      => $minL,
        'amp'       => $amp,
        'deltaPct'  => $deltaPct,
        'refShort'  => $refShort,
        'refLong'   => $refLong,
        'entry_short_calc' => $refShort * (1 - $deltaPct/100),
        'entry_long_calc'  => $refLong  * (1 + $deltaPct/100),
        'last_close' => (float)$rows[0][4],
    ];
}

$pdo = Database::pdo();
$rows = $pdo->query("SELECT id, symbol, side, entry_ref, mode FROM trades ORDER BY id DESC LIMIT 5")->fetchAll(\PDO::FETCH_ASSOC);

echo str_pad('id', 4) . str_pad('symbol', 12) . str_pad('side', 7) . str_pad('mode', 9)
   . str_pad('entry_ref', 12) . str_pad('mainnet_calc', 14) . str_pad('mainnet_close', 15) . "diff%\n";
echo str_repeat('-', 80) . "\n";

foreach ($rows as $r) {
    $sym = $r['symbol'];
    $a = analyze($sym);
    if (!$a['ok']) {
        echo str_pad((string)$r['id'], 4) . str_pad($sym, 12) . str_pad($r['side'], 7)
           . str_pad($r['mode'], 9) . str_pad((string)$r['entry_ref'], 12) . "FETCH FAIL\n";
        continue;
    }
    $entryCalc = $r['side'] === 'short' ? $a['entry_short_calc'] : $a['entry_long_calc'];
    $diff = (((float)$r['entry_ref']) - $entryCalc) / $entryCalc * 100.0;
    echo str_pad((string)$r['id'], 4)
       . str_pad($sym, 12)
       . str_pad($r['side'], 7)
       . str_pad($r['mode'], 9)
       . str_pad(sprintf('%.6f', (float)$r['entry_ref']), 12)
       . str_pad(sprintf('%.6f', $entryCalc), 14)
       . str_pad(sprintf('%.6f', $a['last_close']), 15)
       . sprintf("%+.2f%%\n", $diff);
}

echo "\nДеталь по каждому символу:\n";
foreach ($rows as $r) {
    $a = analyze($r['symbol']);
    if (!$a['ok']) continue;
    printf("%-12s amp=%.3f%% deltaPct=%.4f%% refShort=%.6f refLong=%.6f close=%.6f\n",
        $r['symbol'], $a['amp'], $a['deltaPct'], $a['refShort'], $a['refLong'], $a['last_close']);
}
