#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * v0.9.0-step6.1: освежить trades.account_name из текущего bybit_accounts.name.
 *
 * Зачем: trades.account_name — денормализованный снимок имени аккаунта на момент
 * создания сделки. После переименования аккаунта (rename) или после миграции
 * migrate_to_multi_account.php (которая проставила всем старым live-сделкам имя
 * первого аккаунта в списке) — снимок устаревает и в UI /trades в колонке
 * "acc: name" показывается старое/неправильное имя.
 *
 * Скрипт обновляет trades.account_name на текущее bybit_accounts.name для всех
 * сделок с account_id IS NOT NULL. Историю это не «портит» — мы просто
 * подтягиваем человекочитаемое имя к текущему значению. Связь по account_id
 * остаётся корректной.
 *
 * Использование:
 *   php bin/refresh_account_names.php --dry-run   — показать сколько обновится
 *   php bin/refresh_account_names.php --apply     — применить (по умолчанию)
 */

use BybitBot\Core\Bootstrap;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

$dryRun = in_array('--dry-run', $argv ?? [], true);
echo "refresh_account_names v0.9.0-step6.1" . ($dryRun ? " [dry-run]" : "") . "\n\n";

$pdo = Database::pdo();

// Diff-выборка: сделки, где account_name отличается от текущего имени аккаунта.
$diffRows = $pdo->query(
    "SELECT t.id, t.mode, t.symbol, t.account_id,
            t.account_name AS old_name,
            a.name         AS new_name
       FROM trades t
       JOIN bybit_accounts a ON a.id = t.account_id
      WHERE t.account_id IS NOT NULL
        AND (t.account_name IS NULL OR t.account_name <> a.name)
      ORDER BY t.id DESC"
)->fetchAll(\PDO::FETCH_ASSOC);

if (empty($diffRows)) {
    echo "Все trades.account_name уже соответствуют текущим bybit_accounts.name. Нечего обновлять.\n";
    exit(0);
}

echo "Обнаружено сделок с устаревшим account_name: " . count($diffRows) . "\n\n";

// Сводка: было → стало по парам.
$summary = [];
foreach ($diffRows as $r) {
    $key = ($r['old_name'] ?? '(null)') . " → " . $r['new_name'];
    if (!isset($summary[$key])) {
        $summary[$key] = ['count' => 0, 'ids' => []];
    }
    $summary[$key]['count']++;
    if (count($summary[$key]['ids']) < 10) {
        $summary[$key]['ids'][] = '#' . $r['id'];
    }
}

foreach ($summary as $pair => $info) {
    $idsStr = implode(', ', $info['ids']);
    if ($info['count'] > count($info['ids'])) {
        $idsStr .= " (+ ещё " . ($info['count'] - count($info['ids'])) . ")";
    }
    echo "  {$pair}: {$info['count']} сделок [{$idsStr}]\n";
}

if ($dryRun) {
    echo "\n[dry-run] Для применения запустите без --dry-run.\n";
    exit(0);
}

echo "\nПрименяю UPDATE...\n";

$upd = $pdo->exec(
    "UPDATE trades
        SET account_name = (
            SELECT name FROM bybit_accounts WHERE id = trades.account_id
        )
      WHERE account_id IS NOT NULL
        AND (account_name IS NULL OR account_name <> (
            SELECT name FROM bybit_accounts WHERE id = trades.account_id
        ))"
);
echo "Обновлено trades: {$upd}\n";

try {
    EventRecorder::event(EventRecorder::INFO, 'account_names_refreshed', null, [
        'updated' => (int)$upd,
        'pairs'   => array_map(static function ($k, $v) {
            return ['pair' => $k, 'count' => $v['count']];
        }, array_keys($summary), array_values($summary)),
    ]);
} catch (\Throwable $e) {
    // ignore
}

echo "Готово.\n";
