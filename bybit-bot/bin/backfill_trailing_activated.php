<?php
/**
 * Разовый бэкфилл trailing_activated_at для уже открытых сделок.
 *
 * Эвристика активации (для OPEN/AVERAGED трейдов, где trailing_activated_at IS NULL):
 *
 *   short:  (positions.last_price <= trades.trailing_trigger)
 *           OR (positions.sl_price < trades.sl_init  -- SL подтянулся вниз = трейлинг работал)
 *
 *   long:   (positions.last_price >= trades.trailing_trigger)
 *           OR (positions.sl_price > trades.sl_init  -- SL подтянулся вверх = трейлинг работал)
 *
 * Если эвристика совпала, ставим trailing_activated_at = текущий момент (UTC ISO).
 * Это «задним числом», точное время не восстановить.
 *
 * Запуск:
 *   php bin/backfill_trailing_activated.php           # dry-run (только показать)
 *   php bin/backfill_trailing_activated.php --apply   # реально записать
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Core/Bootstrap.php';
require $root . '/vendor/autoload.php';
\BybitBot\Core\Bootstrap::init($root);

$apply = in_array('--apply', $argv, true);

$pdo = \BybitBot\Core\Database::pdo();

$sql = "
SELECT t.id, t.symbol, t.side, t.status,
       t.entry_real, t.sl_init, t.sl_current,
       t.trailing_trigger, t.trailing_activated_at,
       p.last_price, p.sl_price
FROM trades t
LEFT JOIN positions p ON p.trade_id = t.id
WHERE t.status IN ('OPEN','AVERAGED')
  AND t.trailing_activated_at IS NULL
  AND t.trailing_trigger IS NOT NULL
ORDER BY t.id
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Нет открытых трейдов с пустым trailing_activated_at. Ничего делать не нужно.\n";
    exit(0);
}

$now = gmdate('Y-m-d\TH:i:s\Z');
$toMark = [];

echo "Анализирую " . count($rows) . " трейдов:\n\n";
printf("%-4s %-12s %-6s %-9s %-10s %-10s %-10s %-10s %-10s  %s\n",
    'ID', 'Symbol', 'Side', 'Status', 'Entry', 'SL_init', 'SL_pos', 'Trigger', 'LastPrice', 'Решение');
echo str_repeat('-', 120) . "\n";

foreach ($rows as $r) {
    $id      = (int)$r['id'];
    $side    = $r['side']; // 'short' | 'long'
    $trigger = $r['trailing_trigger'] !== null ? (float)$r['trailing_trigger'] : null;
    $last    = $r['last_price'] !== null ? (float)$r['last_price'] : null;
    $slInit  = $r['sl_init'] !== null ? (float)$r['sl_init'] : null;
    $slPos   = $r['sl_price'] !== null ? (float)$r['sl_price'] : null;

    $activated = false;
    $reason = '';

    if ($trigger !== null && $last !== null) {
        if ($side === 'short' && $last <= $trigger) {
            $activated = true;
            $reason = "last<=trigger ({$last}<={$trigger})";
        } elseif ($side === 'long' && $last >= $trigger) {
            $activated = true;
            $reason = "last>=trigger ({$last}>={$trigger})";
        }
    }

    if (!$activated && $slInit !== null && $slPos !== null) {
        $eps = 1e-12;
        if ($side === 'short' && $slPos + $eps < $slInit) {
            $activated = true;
            $reason = "sl_pos<sl_init ({$slPos}<{$slInit}) — SL подтянут вниз";
        } elseif ($side === 'long' && $slPos > $slInit + $eps) {
            $activated = true;
            $reason = "sl_pos>sl_init ({$slPos}>{$slInit}) — SL подтянут вверх";
        }
    }

    printf("%-4d %-12s %-6s %-9s %-10s %-10s %-10s %-10s %-10s  %s\n",
        $id,
        $r['symbol'],
        $side,
        $r['status'],
        $r['entry_real'] ?? '-',
        $slInit ?? '-',
        $slPos ?? '-',
        $trigger ?? '-',
        $last ?? '-',
        $activated ? "АКТИВИРОВАН: {$reason}" : "не активирован"
    );

    if ($activated) {
        $toMark[] = $id;
    }
}

echo "\n";
echo "К отметке как активированные: " . count($toMark) . " трейд(ов)";
if ($toMark) echo " — IDs: " . implode(', ', $toMark);
echo "\n\n";

if (!$apply) {
    echo "DRY-RUN. Ничего не записано.\n";
    echo "Запустите с --apply, чтобы реально проставить trailing_activated_at:\n";
    echo "  php bin/backfill_trailing_activated.php --apply\n";
    exit(0);
}

if (!$toMark) {
    echo "Записывать нечего.\n";
    exit(0);
}

$stmt = $pdo->prepare(
    'UPDATE trades SET trailing_activated_at = :ts
     WHERE id = :id AND trailing_activated_at IS NULL'
);
$updated = 0;
foreach ($toMark as $id) {
    $stmt->execute([':ts' => $now, ':id' => $id]);
    $updated += $stmt->rowCount();
}

echo "Готово. Обновлено строк: {$updated}. trailing_activated_at = {$now}\n";
