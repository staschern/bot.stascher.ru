<?php
declare(strict_types=1);

namespace BybitBot\Strategies\Strategy3;

use BybitBot\Strategies\StrategyInterface;

/**
 * Strategy 3 — ручная entry + расстояние в %.
 *
 * См. spec.md §18.3.
 *
 * ⚠ Полная реализация в Этапе 3+. Сейчас — заглушка.
 */
final class Strategy3 implements StrategyInterface
{
    public function id(): string { return 's3'; }
    public function name(): string { return 'Strategy 3 — ручная entry+distance%'; }
    public function isAutomatic(): bool { return false; }

    public function collectAutoIntents(array $context): array
    {
        return [];
    }

    public function createManualIntent(array $userInput, array $context): array
    {
        throw new \LogicException('Strategy3: createManualIntent — реализация в Этапе 3.');
    }

    public function onPositionOpened(array $trade, array $context): void
    {
        // TODO Этап 3: §18.3.4
        //  1. Минутный скрипт уже отменил остальные S3-conditional на этом символе (шаг 4).
        //  2. p_for_strategy_calc = distance_pct.
        //  3. Стандартные действия §6 (наследует поведение S1).
    }
}
