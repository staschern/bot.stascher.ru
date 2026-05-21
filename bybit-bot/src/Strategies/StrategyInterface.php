<?php
declare(strict_types=1);

namespace BybitBot\Strategies;

/**
 * Контракт стратегии. См. spec.md §18.
 *
 * Каждая стратегия — модуль:
 *  - либо автоматическая (вызывается из cron_hourly): collectAutoIntents()
 *  - либо ручная (создаётся из UI): createManualIntent()
 *
 * После открытия позиции вызывается onPositionOpened() для специфичных действий
 * (например, S2 ставит дополнительный 60%-TP reduce-only).
 */
interface StrategyInterface
{
    /** Идентификатор стратегии: 's1' | 's2' | 's3' */
    public function id(): string;

    /** Человекочитаемое имя для UI */
    public function name(): string;

    /** true для автоматических (вызываются из cron_hourly) */
    public function isAutomatic(): bool;

    /**
     * Только для автоматических стратегий: собирает 0..N намерений на постановку conditional.
     * Каждое намерение — массив с уже рассчитанными параметрами ордера (entry/TP/SL/qty/leverage).
     *
     * @param array $context  Контекст вызова: signals, instruments, positions, orders, deposit_anchor.
     * @return array<int, array> Список intents.
     */
    public function collectAutoIntents(array $context): array;

    /**
     * Только для ручных стратегий: валидирует пользовательский ввод и формирует намерение.
     * Бросает исключения на ошибках валидации.
     *
     * @param array $userInput  Поля формы (см. §18.2.1 / §18.3.1).
     * @param array $context    Тот же контекст, что и для collectAutoIntents.
     * @return array Intent.
     */
    public function createManualIntent(array $userInput, array $context): array;

    /**
     * Хук, вызываемый минутным скриптом сразу после перехода сделки в OPEN.
     * Здесь стратегия делает специфичные действия (S2: выставить reduce-only 60%-TP).
     *
     * @param array $trade      Запись из таблицы trades (после обновления entry_real).
     * @param array $context    Контекст (адаптер биржи и т.п.).
     */
    public function onPositionOpened(array $trade, array $context): void;
}
