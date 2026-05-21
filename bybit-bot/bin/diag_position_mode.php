<?php
/**
 * v0.8.0.14: диагностика position mode (One-Way vs Hedge) для символа на Bybit.
 *
 * Использование:
 *   php bin/diag_position_mode.php XDCUSDT
 *   php bin/diag_position_mode.php XDCUSDT live
 *
 * Что делает:
 *   1. Берёт текущую позицию символа через GET /v5/position/list
 *   2. Анализирует positionIdx: 0 → One-Way, 1/2 → Hedge
 *   3. Выдаёт рекомендацию: какой режим использовать боту
 *
 * Bybit V5 docs:
 *   https://bybit-exchange.github.io/docs/v5/position
 *   https://bybit-exchange.github.io/docs/v5/position/position-mode
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
\BybitBot\Core\Bootstrap::init(__DIR__ . '/..');

$symbol   = strtoupper((string)($argv[1] ?? 'XDCUSDT'));
$exchange = (string)($argv[2] ?? 'live');

if (!in_array($exchange, ['live', 'testnet'], true)) {
    fwrite(STDERR, "exchange должен быть 'live' или 'testnet', получено: {$exchange}\n");
    exit(2);
}

echo "─── diag_position_mode: {$symbol} ({$exchange}) ───\n\n";

$adapter = new \BybitBot\Exchange\BybitAdapter($exchange);
$client  = new ReflectionProperty($adapter, 'client');
$client->setAccessible(true);
/** @var \BybitBot\Bybit\Client $cli */
$cli = $client->getValue($adapter);

// 1. Текущая позиция (если есть)
$resp = $cli->getPositions($symbol);
echo "GET /v5/position/list → retCode=" . ($resp['ret_code'] ?? $resp['retCode'] ?? '?')
   . " retMsg=" . ($resp['ret_msg'] ?? $resp['retMsg'] ?? '') . "\n";

$rows = $resp['result']['list'] ?? [];
if (empty($rows)) {
    echo "  (нет позиций по {$symbol})\n";
}

$idxValues = [];
foreach ($rows as $row) {
    $idx  = (int)($row['positionIdx'] ?? -1);
    $side = (string)($row['side'] ?? '');
    $size = (string)($row['size'] ?? '');
    $idxValues[] = $idx;
    echo "  positionIdx={$idx} side={$side} size={$size}\n";
}

// 2. Анализ
echo "\n─── Анализ ───\n";

$hasZero = in_array(0, $idxValues, true);
$hasOneOrTwo = !empty(array_intersect([1, 2], $idxValues));

if ($hasZero && !$hasOneOrTwo) {
    echo "Режим: ONE-WAY (positionIdx=0). Бот должен слать positionIdx=0.\n";
    echo "Рекомендация для боте: BybitAdapter::setHedgeMode('{$symbol}', false)\n";
} elseif (!$hasZero && $hasOneOrTwo) {
    echo "Режим: HEDGE (positionIdx 1/2). Бот должен слать positionIdx=1 для Buy/long, =2 для Sell/short.\n";
    echo "Рекомендация для боте: BybitAdapter::setHedgeMode('{$symbol}', true)\n";
} elseif (empty($rows)) {
    echo "Позиций нет. Попробуем определить через тестовый ордер с обоими режимами…\n";
    echo "Совет: проверить вручную в Bybit Web → Derivatives → Position Mode.\n";
    echo "В v0.8.0.14 бот автоматически определит режим при первой попытке размещения.\n";
} else {
    echo "Странное состояние: смешанные positionIdx значения.\n";
    var_export($idxValues);
}

// 3. Текущий кэш в адаптере (если был выставлен явно где-то ранее)
echo "\n─── Кэш адаптера ───\n";
$cached = \BybitBot\Exchange\BybitAdapter::getCachedHedgeMode($symbol);
if ($cached === null) {
    echo "Кэш пуст (адаптер инициализирован в этом процессе только сейчас).\n";
    echo "В рантайме cron_hourly кэш будет обновлён после успешного размещения.\n";
} else {
    echo "Кэшированный режим: " . ($cached ? 'HEDGE' : 'ONE-WAY') . "\n";
}

echo "\nГотово.\n";
