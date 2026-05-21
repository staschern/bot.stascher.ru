<?php
declare(strict_types=1);

namespace BybitBot\Strategies\Strategy2;

use BybitBot\Strategies\Strategy1\Strategy1;
use BybitBot\Strategies\StrategyInterface;

/**
 * Strategy 2 — ручной ввод условных ордеров (v0.6.0).
 *
 * Создание трейда происходит через `BybitBot\Trade\ManualOrderService` (UI-форма
 * на странице `/trades`, см. spec.md §15). Этот класс нужен только чтобы
 * `cron_minute` мог найти реализацию в `StrategyRegistry::get('s2')` для хуков
 * сопровождения.
 *
 * Логика сопровождения после открытия позиции — **ровно та же**, что у
 * Strategy 1 (трейлинг §6.3, SL_real §6.2, avg §6.4, post-avg §7), поэтому
 * onPositionOpened/Averaged просто делегируют в Strategy1.
 */
final class Strategy2 implements StrategyInterface
{
    /** @var Strategy1|null */
    private $strat1;

    public function id(): string { return 's2'; }
    public function name(): string { return 'Strategy 2 — ручной ввод (s2)'; }
    public function isAutomatic(): bool { return false; }

    public function collectAutoIntents(array $context): array
    {
        // Ручная стратегия — автоматических намерений не порождает.
        return [];
    }

    public function createManualIntent(array $userInput, array $context): array
    {
        // Совместимость с интерфейсом. Реальный путь — ManualOrderService::submit().
        throw new \LogicException(
            'Strategy2: ручной ввод обрабатывается через BybitBot\\Trade\\ManualOrderService::submit().'
        );
    }

    /**
     * Сопровождение после открытия — делегируем Strategy1 (общая логика §6).
     */
    public function onPositionOpened(array $trade, array $context): void
    {
        $this->strat1()->onPositionOpened($trade, $context);
    }

    /**
     * Усреднение — делегируем Strategy1 (общая логика §7).
     */
    public function onPositionAveraged(array $trade, array $context): void
    {
        $this->strat1()->onPositionAveraged($trade, $context);
    }

    private function strat1(): Strategy1
    {
        if ($this->strat1 === null) {
            $this->strat1 = new Strategy1();
        }
        return $this->strat1;
    }
}
