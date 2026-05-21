<?php
declare(strict_types=1);

/**
 * Реестр стратегий.
 *
 * Каждый ключ должен соответствовать `id` в таблице `strategies` (s1/s2/s3).
 * Поле `class` указывает на FQCN, реализующий BybitBot\Strategies\StrategyInterface.
 *
 * См. spec.md §18.
 */

return [
    's1' => [
        'name'         => 'Strategy 1 — авто signalsHourly',
        'class'        => \BybitBot\Strategies\Strategy1\Strategy1::class,
        'enabled'      => true,
        'is_automatic' => true,
        'description'  => 'Автоматическая стратегия по signalsHourly.json. Защиты в режиме block.',
    ],
    's2' => [
        'name'         => 'Strategy 2 — ручная по абсолютным ценам',
        'class'        => \BybitBot\Strategies\Strategy2\Strategy2::class,
        'enabled'      => false,
        'is_automatic' => false,
        'description'  => 'Ручная: entry/TP/SL в абсолютных ценах. Защиты в режиме warn. Будет реализована в Этапе 3+.',
    ],
    's3' => [
        'name'         => 'Strategy 3 — ручная entry+distance%',
        'class'        => \BybitBot\Strategies\Strategy3\Strategy3::class,
        'enabled'      => false,
        'is_automatic' => false,
        'description'  => 'Ручная: entry + distance%, до 2 conditional на тикер. Защиты warn. Будет реализована в Этапе 3+.',
    ],
];
