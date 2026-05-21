#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * v0.9.0-step6.2: диагностика fan-out по аккаунтам.
 *
 * Проверяет:
 *   1) Кого видит BybitAccountsRepo::getEnabledForNetwork('live') — какие аккаунты,
 *      в каком порядке. Это ровно то, что fan-out перебирает в cron_hourly.
 *   2) Имеют ли все enabled-аккаунты валидные API-ключи (есть запись в secrets).
 *   3) Для каждого аккаунта — getWalletBalance(), getOpenOrders(count), getPositions(count).
 *   4) Сколько сделок в trades за последние 24h на каком account_id (фактическая статистика fan-out).
 *
 * Использование:
 *   sudo -u www-root php bin/diag_fanout.php
 */

use BybitBot\Core\Bootstrap;
use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Database;
use BybitBot\Exchange\AdapterFactory;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

$pdo = Database::pdo();

echo "=== 1. Все аккаунты (вкл. archived) ===\n";
$rows = $pdo->query(
    "SELECT id, name, network, enabled, archived_at, api_key_mask
       FROM bybit_accounts
      ORDER BY id ASC"
)->fetchAll(\PDO::FETCH_ASSOC);
$secretsDir = $root . '/data/secrets';
foreach ($rows as $r) {
    $aid       = (int)$r['id'];
    $secretPath = $secretsDir . '/account_' . $aid . '.php';
    $hasSecret = is_file($secretPath) ? 'YES' : 'NO';
    printf("  #%-2d %-15s network=%-8s enabled=%d archived=%-5s mask=%-20s secret_file=%s\n",
        $aid, $r['name'], $r['network'], $r['enabled'],
        $r['archived_at'] ?? 'no', $r['api_key_mask'] ?? '', $hasSecret);
}

echo "\n=== 2. getEnabledForNetwork('live') ===\n";
$enabled = BybitAccountsRepo::getEnabledForNetwork('live');
if (empty($enabled)) {
    echo "  ПУСТО — fan-out НИЧЕГО не сделает.\n";
} else {
    echo "  Найдено: " . count($enabled) . " аккаунт(ов). Порядок (это порядок fan-out):\n";
    foreach ($enabled as $a) {
        printf("    → #%-2d %-15s network=%s mask=%s\n",
            $a['id'], $a['name'], $a['network'] ?? '?', $a['api_key_mask'] ?? '?');
    }
}

echo "\n=== 3. Per-account проверка адаптера ===\n";
foreach ($enabled as $a) {
    $accId   = (int)$a['id'];
    $accName = (string)$a['name'];
    echo "\n  --- #{$accId} {$accName} ---\n";
    try {
        $adapter = AdapterFactory::forAccount($accId);
    } catch (\Throwable $e) {
        echo "    forAccount() FAILED: " . $e->getMessage() . "\n";
        continue;
    }
    try {
        $bal = $adapter->getWalletBalance();
        $totalWB = $bal['totalWalletBalance'] ?? '?';
        echo "    walletBalance.totalWalletBalance = {$totalWB}\n";
    } catch (\Throwable $e) {
        echo "    getWalletBalance() FAILED: " . $e->getMessage() . "\n";
    }
    try {
        $ords = $adapter->getOpenOrders();
        echo "    getOpenOrders() count = " . count($ords) . "\n";
    } catch (\Throwable $e) {
        echo "    getOpenOrders() FAILED: " . $e->getMessage() . "\n";
    }
    try {
        $poss = $adapter->getPositions();
        $alive = 0;
        foreach ($poss as $p) {
            $size = isset($p['size']) ? (float)$p['size'] : (float)($p['qty'] ?? 0);
            if ($size > 0) $alive++;
        }
        echo "    getPositions() count={$alive} (живых)\n";
    } catch (\Throwable $e) {
        echo "    getPositions() FAILED: " . $e->getMessage() . "\n";
    }
}

echo "\n=== 4. trades за последние 48h по account_id ===\n";
$rows = $pdo->query(
    "SELECT account_id, account_name, COUNT(*) AS cnt,
            MIN(id) AS min_id, MAX(id) AS max_id
       FROM trades
      WHERE mode='live'
        AND datetime(created_at) >= datetime('now','-48 hours')
   GROUP BY account_id, account_name
   ORDER BY account_id"
)->fetchAll(\PDO::FETCH_ASSOC);
if (empty($rows)) {
    echo "  Нет live-сделок за 48h.\n";
} else {
    foreach ($rows as $r) {
        printf("  account_id=%s name=%-15s cnt=%-3d ids=#%d..#%d\n",
            $r['account_id'] ?? 'NULL',
            $r['account_name'] ?? '(null)',
            $r['cnt'], $r['min_id'], $r['max_id']);
    }
}

echo "\n=== 5. Последние 20 INFO/WARN из logs/app.log с тегами fan-out/conditional ===\n";
$logFile = $root . '/logs/app.log';
if (is_file($logFile)) {
    $cmd = "grep -E 'cron_hourly: (account fan-out start|запуск стратегии)|placeConditional|forAccount' "
        . escapeshellarg($logFile) . " 2>/dev/null | tail -30";
    passthru($cmd);
} else {
    echo "  logs/app.log не найден.\n";
}

echo "\nГотово.\n";
