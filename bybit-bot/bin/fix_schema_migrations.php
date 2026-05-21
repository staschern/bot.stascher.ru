<?php
/**
 * Разовый фикс: помечает миграции 001..011 как применённые в schema_migrations,
 * если они отсутствуют. Безопасно — реальный SQL не выполняется, только INSERT OR IGNORE.
 *
 * После этого `php bin/cli.php migrate` применит только 012.
 *
 * Запуск:
 *   php bin/fix_schema_migrations.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Core/Bootstrap.php';
require $root . '/vendor/autoload.php';
\BybitBot\Core\Bootstrap::init($root);

$pdo = \BybitBot\Core\Database::pdo();

// На всякий случай создаём таблицу, если вдруг её нет.
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version    TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL
)');

// Текущее содержимое schema_migrations.
$existing = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
echo "Сейчас в schema_migrations:\n";
if (!$existing) {
    echo "  (пусто)\n";
} else {
    foreach ($existing as $v) echo "  - {$v}\n";
}
echo "\n";

// Все миграции, которые надо считать применёнными (НЕ включая 012 — её применит migrate).
$toMark = [
    '001_initial_schema',
    '002_signals_and_instruments',
    '003_orders_positions',
    '004_unified_exchange',
    '005_rename_signal_type',
    '006_orders_last_seen_price',
    '007_positions_last_price',
    '008_qty_safety_margin',
    '009_trade_color_label',
    '010_symbol_aliases_exchange',
    '011_signals_decisions',
];

$now = gmdate('Y-m-d\TH:i:s\Z');
$stmt = $pdo->prepare(
    'INSERT OR IGNORE INTO schema_migrations (version, applied_at) VALUES (:v, :t)'
);

$inserted = [];
foreach ($toMark as $v) {
    $stmt->execute([':v' => $v, ':t' => $now]);
    if ($stmt->rowCount() > 0) {
        $inserted[] = $v;
    }
}

if ($inserted) {
    echo "Добавлены как применённые:\n";
    foreach ($inserted as $v) echo "  + {$v}\n";
} else {
    echo "Новых записей не добавлено (всё уже было).\n";
}

echo "\nИтоговое содержимое schema_migrations:\n";
$final = $pdo->query('SELECT version, applied_at FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
foreach ($final as $row) {
    echo "  - {$row['version']}  ({$row['applied_at']})\n";
}

echo "\nГотово. Теперь можно запускать: php bin/cli.php migrate\n";
echo "Он применит только 012_trailing_activated.\n";
