<?php
declare(strict_types=1);

namespace BybitBot\Guards;

/**
 * Контракт для классов защиты (Guards). См. spec.md §9.
 *
 * Каждая защита проверяет одно условие и возвращает результат:
 *  - пустой массив => защита не сработала (ок)
 *  - массив с элементами => защита сработала, каждый элемент:
 *    ['guard' => string, 'message' => string, 'critical' => bool]
 */
interface GuardsInterface
{
    /**
     * Проверить защиту.
     *
     * @param array $intent  Параметры намерения (symbol, side, entry_ref, qty, leverage, ...)
     * @param array $context Контекст вызова (positions, orders, deposit_anchor, adapter, ...)
     * @return array<int, array{guard:string, message:string, critical:bool}> Сработавшие защиты
     */
    public function check(array $intent, array $context): array;
}
