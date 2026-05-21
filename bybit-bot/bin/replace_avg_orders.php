#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * replace_avg_orders — восстановление avg-ордеров для уже открытых позиций.
 *
 * Используется один раз после фикса phantom-cancellation бага (v0.9.0-step8).
 * Для каждой OPEN/AVERAGED сделки на заданном account_id:
 *  1. Снимает на бирже текущий avg-ордер по trades.order_link_id_avg (если ещё жив).
 *  2. Сбрасывает trades.order_link_id_avg / avg_price / qty_avg = NULL.
 *  3. Вызывает Strategy1::onPositionOpened — он пересчитает avg и поставит новый
 *     conditional. INSERT в orders сделает placeConditional(), запись будет
 *     корректно привязана к account_id.
 *
 * Usage:
 *   sudo -u www-root php bin/replace_avg_orders.php --account=2 [--dry-run]
 *   sudo -u www-root php bin/replace_avg_orders.php --trade=83
 *
 * v0.9.0-step8.
 */

use BybitBot\Core\Bootstrap;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use BybitBot\Exchange\BybitAdapter;
use BybitBot\Strategies\StrategyRegistry;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Bootstrap.php';
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

// Парсим аргументы.
$opts = getopt('', ['account::', 'trade::', 'dry-run']);
$accountId = isset($opts['account']) ? (int)$opts['account'] : null;
$tradeId   = isset($opts['trade'])   ? (int)$opts['trade']   : null;
$dryRun    = array_key_exists('dry-run', $opts);

if ($accountId === null && $tradeId === null) {
    fwrite(STDERR, "Usage: replace_avg_orders.php --account=<id> [--dry-run]\n");
    fwrite(STDERR, "       replace_avg_orders.php --trade=<id>   [--dry-run]\n");
    exit(2);
}

$pdo = Database::pdo();

// 1. Выбираем кандидатов.
if ($tradeId !== null) {
    $stmt = $pdo->prepare(
        "SELECT * FROM trades WHERE id = :id"
    );
    $stmt->execute([':id' => $tradeId]);
} else {
    $stmt = $pdo->prepare(
        "SELECT * FROM trades
         WHERE account_id = :acc
           AND status IN ('OPEN','AVERAGED')
           AND mode IN ('testnet','live')
         ORDER BY id"
    );
    $stmt->execute([':acc' => $accountId]);
}
$trades = $stmt->fetchAll();

if (empty($trades)) {
    echo "Нет кандидатов (account={$accountId}, trade={$tradeId}).\n";
    exit(0);
}

echo "Найдено сделок: " . count($trades) . "\n";
echo ($dryRun ? "[DRY-RUN]" : "[LIVE]") . " режим\n\n";

// Загружаем стратегии (для onPositionOpened).
$cfgPath = $root . '/config/strategies.php';
$strategiesConfig = require $cfgPath;
$registry = new StrategyRegistry($strategiesConfig);

// Кэш адаптеров по (exchange, accountId).
$adapterCache = [];
$getAdapter = function (string $exchange, int $accId) use (&$adapterCache): BybitAdapter {
    $key = $exchange . ':' . $accId;
    if (!isset($adapterCache[$key])) {
        $adapterCache[$key] = new BybitAdapter($exchange, $accId);
    }
    return $adapterCache[$key];
};

// Deposit anchor (для onPositionOpened).
$loadDepositAnchor = function (string $exchange) use ($pdo): float {
    $s = $pdo->prepare("SELECT value FROM deposit_snapshots WHERE mode = :m ORDER BY ts DESC LIMIT 1");
    $s->execute([':m' => $exchange]);
    $v = $s->fetchColumn();
    if ($v !== false) {
        return (float)$v;
    }
    return (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
};

$okCount = 0; $skipCount = 0; $failCount = 0;

foreach ($trades as $trade) {
    $tid       = (int)$trade['id'];
    $sym       = (string)$trade['symbol'];
    $accId     = (int)$trade['account_id'];
    $exch      = (string)$trade['mode'];
    $oldLink   = (string)($trade['order_link_id_avg'] ?? '');
    $strategy  = (string)$trade['strategy_id'];

    echo "--- trade #{$tid} {$sym} ({$strategy}) acc={$accId} exch={$exch}\n";
    echo "    old avg link: " . ($oldLink ?: '(пусто)') . "\n";

    try {
        $adapter = $getAdapter($exch, $accId);

        // a) Снимаем старый ордер на бирже (если жив).
        if ($oldLink !== '') {
            if ($dryRun) {
                echo "    [dry] cancelOrder({$oldLink})\n";
            } else {
                try {
                    $adapter->cancelOrder($oldLink);
                    echo "    cancelOrder OK\n";
                } catch (\Throwable $e) {
                    echo "    cancelOrder error (вероятно уже отменён): " . $e->getMessage() . "\n";
                }
            }
        }

        // b) Сбрасываем avg-поля в trades и пометим старые orders cancelled (если ещё placed).
        if (!$dryRun) {
            $pdo->prepare(
                "UPDATE trades SET order_link_id_avg = NULL, avg_price = NULL, qty_avg = NULL WHERE id = :id"
            )->execute([':id' => $tid]);

            $pdo->prepare(
                "UPDATE orders SET status = 'cancelled', cancelled_at = :now
                 WHERE trade_id = :tid AND purpose = 'avg' AND status IN ('pending','placed')"
            )->execute([
                ':now' => gmdate('Y-m-d\\TH:i:s.v\\Z'),
                ':tid' => $tid,
            ]);
            echo "    БД: trades сброшены, orders avg -> cancelled\n";
        } else {
            echo "    [dry] сброс trades.order_link_id_avg/avg_price/qty_avg + orders.avg -> cancelled\n";
        }

        // c) Перечитываем trade и вызываем onPositionOpened.
        $freshStmt = $pdo->prepare("SELECT * FROM trades WHERE id = :id");
        $freshStmt->execute([':id' => $tid]);
        $fresh = $freshStmt->fetch();
        if (!$fresh) {
            echo "    trade исчез — пропуск\n";
            $skipCount++;
            continue;
        }

        if (empty($fresh['entry_real'])) {
            echo "    entry_real пуст — нельзя пересчитать avg, пропуск\n";
            $skipCount++;
            continue;
        }

        $strat = $registry->get($strategy);
        if (!$strat) {
            echo "    стратегия {$strategy} не найдена — пропуск\n";
            $skipCount++;
            continue;
        }

        if ($dryRun) {
            echo "    [dry] onPositionOpened(trade #{$tid})\n";
            $okCount++;
            continue;
        }

        $context = [
            'adapter'        => $adapter,
            'deposit_anchor' => $loadDepositAnchor($exch),
            'mode'           => $exch,
        ];

        $strat->onPositionOpened($fresh, $context);

        EventRecorder::tradeEvent($tid, EventRecorder::INFO, 'avg_replaced_by_script', [
            'old_link' => $oldLink,
            'reason'   => 'phantom_cleanup_v0.9.0-step8',
        ]);
        echo "    OK — avg переставлен\n";
        $okCount++;
    } catch (\Throwable $e) {
        echo "    FAIL: " . $e->getMessage() . "\n";
        Logger::get()->error('replace_avg_orders: failed', [
            'trade_id' => $tid,
            'error'    => $e->getMessage(),
        ]);
        $failCount++;
    }
}

echo "\n========= ИТОГ =========\n";
echo "OK:   {$okCount}\n";
echo "SKIP: {$skipCount}\n";
echo "FAIL: {$failCount}\n";
