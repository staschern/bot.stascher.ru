#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * v0.9.0-step4a.2: повторный backfill account_id для orders и positions.
 *
 * Срочный фикс: BybitAdapter::placeConditional до v0.9.0-step4a.2 не записывал
 * account_id в orders при INSERT. В результате после деплоя step3/step4a
 * новые ордера создавались с account_id = NULL → LiveReconciler с фильтром
 * по account_id их не видел → принимал ремоутные за фантомы → пытался отменить.
 *
 * Этот скрипт:
 *   1. Для всех orders с account_id IS NULL И trade_id у которого есть account_id
 *      — копирует trades.account_id в orders.account_id.
 *   2. То же самое для positions.
 *
 * Запуск: php bin/backfill_orders_account_id.php [--dry-run]
 */

use BybitBot\Core\Bootstrap;
use BybitBot\Core\Database;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Bootstrap.php';
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

$dryRun = in_array('--dry-run', $argv ?? [], true);
echo "backfill_orders_account_id v0.9.0-step4a.2" . ($dryRun ? " [dry-run]" : "") . "\n";

$pdo = Database::pdo();

// orders
$cntOrders = (int)$pdo->query(
    "SELECT COUNT(*) FROM orders o
     JOIN trades t ON t.id = o.trade_id
     WHERE o.account_id IS NULL AND t.account_id IS NOT NULL"
)->fetchColumn();
echo "orders к обновлению: {$cntOrders}\n";

if (!$dryRun && $cntOrders > 0) {
    $upd = $pdo->exec(
        "UPDATE orders
            SET account_id = (
                SELECT t.account_id FROM trades t WHERE t.id = orders.trade_id
            )
          WHERE account_id IS NULL
            AND trade_id IN (SELECT id FROM trades WHERE account_id IS NOT NULL)"
    );
    echo "  обновлено orders: {$upd}\n";
}

// positions
$hasTradeId = false;
try {
    $cols = $pdo->query("PRAGMA table_info(positions)")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if ((string)($c['name'] ?? '') === 'trade_id') { $hasTradeId = true; break; }
    }
} catch (\Throwable $e) {}

if (!$hasTradeId) {
    echo "positions: нет колонки trade_id — пропускаю.\n";
} else {
    $cntPos = (int)$pdo->query(
        "SELECT COUNT(*) FROM positions p
         JOIN trades t ON t.id = p.trade_id
         WHERE p.account_id IS NULL AND t.account_id IS NOT NULL"
    )->fetchColumn();
    echo "positions к обновлению: {$cntPos}\n";

    if (!$dryRun && $cntPos > 0) {
        $upd = $pdo->exec(
            "UPDATE positions
                SET account_id = (
                    SELECT t.account_id FROM trades t WHERE t.id = positions.trade_id
                )
              WHERE account_id IS NULL
                AND trade_id IN (SELECT id FROM trades WHERE account_id IS NOT NULL)"
        );
        echo "  обновлено positions: {$upd}\n";
    }
}

echo "Готово.\n";
