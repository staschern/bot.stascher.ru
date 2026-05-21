<?php
/**
 * v0.8.0.11: разовый сброс серверного трейлинга на Bybit и восстановление
 * корректного SL для активных live-позиций.
 *
 * Что делает:
 *   Для каждой OPEN/AVERAGED live-позиции:
 *   1. Через setTradingStop вызывает clear_trailing (trailingStop=0, activePrice=0) —
 *      снимает кривой серверный трейлинг, выставленный ранее как "процент-как-доллары".
 *   2. Восстанавливает stopLoss = trades.sl_init (исходный SL стратегии).
 *   3. В БД: positions.sl_price = sl_init, trades.sl_current = sl_init,
 *      trades.trailing_activated_at = NULL.
 *
 * После этого LiveReconciler сам отследит пересечение trailing_trigger и начнёт
 * правильно (по процентам) подтягивать SL.
 *
 * Запуск:
 *   php bin/reset_live_trailing.php           # dry-run
 *   php bin/reset_live_trailing.php --apply   # реально выполнить
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Core/Bootstrap.php';
require $root . '/vendor/autoload.php';
\BybitBot\Core\Bootstrap::init($root);

$apply = in_array('--apply', $argv, true);

$pdo = \BybitBot\Core\Database::pdo();

$rows = $pdo->query("
    SELECT t.id AS trade_id, t.symbol, t.side AS trade_side,
           t.sl_init, t.sl_current, t.trailing_pct, t.trailing_trigger,
           t.trailing_activated_at, t.status, t.account_id, t.account_name,
           p.id AS pos_id, p.side AS pos_side, p.sl_price, p.last_price,
           p.exchange
    FROM trades t
    JOIN positions p ON p.trade_id = t.id
    WHERE t.status IN ('OPEN','AVERAGED')
      AND p.exchange = 'live'
      AND p.closed_at IS NULL
    ORDER BY t.id
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Нет открытых live-позиций. Делать нечего.\n";
    exit(0);
}

// v0.9.0-step4d: адаптер per-trade из trades.account_id.
// Legacy-сделки без account_id — через forCurrentMode (при live = mainnet).
$pickAdapter = static function (?int $accId) {
    if ($accId !== null) {
        return \BybitBot\Exchange\AdapterFactory::forAccount($accId);
    }
    return \BybitBot\Exchange\AdapterFactory::forCurrentMode();
};

echo "Найдено активных live-позиций: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    $accId    = isset($r['account_id']) && $r['account_id'] !== null ? (int)$r['account_id'] : null;
    $accLabel = $accId !== null ? " acc=#{$accId}({$r['account_name']})" : '';
    echo "Trade {$r['trade_id']}  {$r['symbol']}  {$r['trade_side']}/{$r['pos_side']}  status={$r['status']}{$accLabel}\n";
    echo "  sl_init={$r['sl_init']}  pos.sl_price={$r['sl_price']}  sl_current={$r['sl_current']}\n";
    echo "  trailing_pct={$r['trailing_pct']}  trailing_trigger={$r['trailing_trigger']}\n";
    echo "  trailing_activated_at=" . ($r['trailing_activated_at'] ?: 'NULL') . "\n";
    echo "  last_price={$r['last_price']}\n";
    if (!$apply) {
        echo "  [dry-run] плановые действия:\n";
        echo "    - Bybit setTradingStop: clear_trailing + stopLoss={$r['sl_init']}\n";
        echo "    - БД: pos.sl_price={$r['sl_init']}, trades.sl_current={$r['sl_init']}, trailing_activated_at=NULL\n";
    } else {
        $slInit = $r['sl_init'] !== null ? (float)$r['sl_init'] : null;
        if ($slInit === null || $slInit <= 0) {
            echo "  ПРОПУСК: sl_init пустой, нечего восстанавливать.\n\n";
            continue;
        }
        try {
            // v0.9.0-step4d: адаптер per-account.
            $adapter = $pickAdapter($accId);
            $ok = $adapter->setTradingStop(
                $r['symbol'],
                $r['pos_side'],
                [
                    'sl_price'       => $slInit,
                    'clear_trailing' => true,
                ]
            );
            echo "  Bybit setTradingStop: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (\Throwable $e) {
            echo "  Bybit setTradingStop: ОШИБКА — " . $e->getMessage() . "\n";
            $ok = false;
        }
        if ($ok) {
            $pdo->prepare('UPDATE positions SET sl_price = :sl WHERE id = :id')
                ->execute([':sl' => $slInit, ':id' => (int)$r['pos_id']]);
            $pdo->prepare(
                'UPDATE trades SET sl_current = :sl, trailing_activated_at = NULL WHERE id = :id'
            )->execute([':sl' => $slInit, ':id' => (int)$r['trade_id']]);
            echo "  БД обновлена.\n";
        } else {
            echo "  БД НЕ обновлена (Bybit вернул ошибку).\n";
        }
    }
    echo "\n";
}

if (!$apply) {
    echo "DRY-RUN. Запустите с --apply, чтобы применить.\n";
} else {
    echo "Готово. Дальше реконсайлер сам пересчитает SL при пересечении trailing_trigger.\n";
}
