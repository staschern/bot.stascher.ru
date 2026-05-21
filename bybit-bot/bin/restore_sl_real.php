<?php
/**
 * v0.8.0.12 hotfix: восстановить sl_current = sl_real по §6.2 spec.md
 *
 * Контекст: bin/reset_live_trailing.php (v0.8.0.11) сбросил sl_current → sl_init,
 * затерев правильное значение SL_real, посчитанное в Strategy1::onPositionOpened.
 * В результате avg_price (по §6.4 с market_coef) оказался ДАЛЬШЕ sl_init —
 * нарушение геометрии entry → avg → SL.
 *
 * Что делает:
 *   1. Берёт все live-OPEN/AVERAGED сделки.
 *   2. Для каждой ищет последнее событие 'position_opened_setup' с sl_real.
 *   3. Если trades.sl_current != sl_real (и sl_real разумен), обновляет:
 *      - trades.sl_current
 *      - positions.sl_price
 *      - Bybit setTradingStop(stopLoss = sl_real)
 *
 * Запуск:
 *   php bin/restore_sl_real.php --dry-run     # план
 *   php bin/restore_sl_real.php               # применить
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
\BybitBot\Core\Bootstrap::init(__DIR__ . '/..');

use BybitBot\Core\Database;
use BybitBot\Exchange\AdapterFactory;

$dryRun = in_array('--dry-run', $argv ?? [], true);
echo $dryRun ? "[DRY-RUN]\n" : "[APPLY]\n";

$pdo = Database::pdo();

$rows = $pdo->query(
    "SELECT t.id, t.symbol, t.side, t.mode, t.entry_real, t.sl_init, t.sl_current,
            t.p_for_strategy_calc, t.status, t.account_id, t.account_name
     FROM trades t
     WHERE t.mode = 'live' AND t.status IN ('OPEN','AVERAGED')
     ORDER BY t.id"
)->fetchAll(\PDO::FETCH_ASSOC);

if (!$rows) { echo "Нет live OPEN/AVERAGED сделок.\n"; exit(0); }

// v0.9.0-step4d: адаптер выбираетсы per-trade из trades.account_id.
// Для legacy-сделок без account_id используем forCurrentMode() (при live = mainnet).
$pickAdapter = static function (?int $accId) {
    if ($accId !== null) {
        return AdapterFactory::forAccount($accId);
    }
    return AdapterFactory::forCurrentMode();
};

$evStmt = $pdo->prepare(
    "SELECT payload_json FROM trade_events
     WHERE trade_id = :t AND kind = 'position_opened_setup'
     ORDER BY id DESC LIMIT 1"
);

$updated = 0; $skipped = 0; $errors = 0;
foreach ($rows as $r) {
    $tid    = (int)$r['id'];
    $symbol = (string)$r['symbol'];
    $side   = (string)$r['side']; // 'long'|'short'
    $isLong = ($side === 'long');
    $sign   = $isLong ? 1.0 : -1.0;
    $slCur  = $r['sl_current'] !== null ? (float)$r['sl_current'] : null;

    $evStmt->execute([':t' => $tid]);
    $payload = $evStmt->fetchColumn();
    if (!$payload) {
        echo "  trade #{$tid} {$symbol} {$side}: НЕТ события position_opened_setup → ПРОПУСК\n";
        $skipped++; continue;
    }
    $j = json_decode((string)$payload, true);
    if (!is_array($j) || !isset($j['sl_real'])) {
        echo "  trade #{$tid} {$symbol} {$side}: setup без sl_real → ПРОПУСК\n";
        $skipped++; continue;
    }
    $slReal = (float)$j['sl_real'];

    // Sanity: SL_real должен быть с правильной стороны от entry_real
    $accId = isset($r['account_id']) && $r['account_id'] !== null ? (int)$r['account_id'] : null;
    $entry = $r['entry_real'] !== null ? (float)$r['entry_real'] : null;
    if ($entry !== null) {
        if ($isLong && $slReal >= $entry) {
            echo "  trade #{$tid} {$symbol} long: sl_real={$slReal} >= entry={$entry} — некорректно, ПРОПУСК\n";
            $skipped++; continue;
        }
        if (!$isLong && $slReal <= $entry) {
            echo "  trade #{$tid} {$symbol} short: sl_real={$slReal} <= entry={$entry} — некорректно, ПРОПУСК\n";
            $skipped++; continue;
        }
    }

    if ($slCur !== null && abs($slCur - $slReal) < 1e-12) {
        echo "  trade #{$tid} {$symbol} {$side}: sl_current уже = sl_real ({$slReal}) — ПРОПУСК\n";
        $skipped++; continue;
    }

    echo sprintf(
        "  trade #%d %s %s: sl_current %s → %.6f (entry_real=%s)\n",
        $tid, $symbol, $side,
        $slCur !== null ? sprintf('%.6f', $slCur) : 'NULL',
        $slReal,
        $entry !== null ? sprintf('%.6f', $entry) : 'NULL'
    );

    if ($dryRun) continue;

    try {
        // 1) Bybit: обновить stopLoss (trailing не трогаем — он остаётся client-side)
        // v0.9.0-step4d: адаптер per-account.
        $adapter = $pickAdapter($accId);
        $bybitSide = $isLong ? 'Buy' : 'Sell';
        $adapter->setTradingStop($symbol, $bybitSide, [
            'sl_price' => $slReal,
        ]);

        // 2) БД: trades.sl_current + positions.sl_price
        $pdo->prepare('UPDATE trades SET sl_current = :sl WHERE id = :id')
            ->execute([':sl' => $slReal, ':id' => $tid]);
        $pdo->prepare(
            "UPDATE positions SET sl_price = :sl
             WHERE trade_id = :id AND exchange = 'live'"
        )->execute([':sl' => $slReal, ':id' => $tid]);

        \BybitBot\Core\EventRecorder::tradeEvent(
            $tid,
            \BybitBot\Core\EventRecorder::INFO,
            'sl_real_restored',
            [
                'sl_was'   => $slCur,
                'sl_now'   => $slReal,
                'symbol'   => $symbol,
                'side'     => $side,
                'reason'   => 'reset_live_trailing rolled sl back to sl_init',
            ]
        );

        echo "    ✓ применено (биржа + БД)\n";
        $updated++;
    } catch (\Throwable $e) {
        echo "    ✗ ОШИБКА: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\nИтого: обновлено={$updated}, пропущено={$skipped}, ошибок={$errors}\n";
