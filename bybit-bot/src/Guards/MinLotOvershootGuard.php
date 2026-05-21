<?php
declare(strict_types=1);

namespace BybitBot\Guards;

use BybitBot\Core\Config;
use BybitBot\Core\EventRecorder;

/**
 * Проверка превышения минимального лота биржи. См. spec.md §5.4.
 *
 * Если qty_min × entry_ref > unleveraged_usdt × (1 + overshoot_pct/100) — блокируем.
 * Если pct = 0 — входим по qty_min без ограничений.
 *
 * Настройки:
 *   min_lot_overshoot.pct (float, default 0 — без ограничения)
 */
final class MinLotOvershootGuard implements GuardsInterface
{
    public function check(array $intent, array $context): array
    {
        $stratId    = (string)($intent['strategy_id'] ?? null);
        $overshootPct = (float)Config::get('min_lot_overshoot.pct', $stratId ?: null, 0.0);

        // Параметры из intent
        $qtyMin        = isset($intent['qty_min'])        ? (float)$intent['qty_min']        : null;
        $entryRef      = isset($intent['entry_ref'])      ? (float)$intent['entry_ref']      : null;
        $unleveraged   = isset($intent['unleveraged_usdt'])? (float)$intent['unleveraged_usdt']: null;

        if ($qtyMin === null || $entryRef === null || $unleveraged === null || $unleveraged <= 0) {
            return []; // Нет данных для проверки
        }

        // Если overshoot_pct = 0 — разрешаем по qty_min без ограничений
        if ($overshootPct <= 0) {
            return [];
        }

        $minLotCost    = $qtyMin * $entryRef;
        $allowedMax    = $unleveraged * (1 + $overshootPct / 100.0);

        if ($minLotCost > $allowedMax) {
            EventRecorder::event(EventRecorder::INFO, 'guard_min_lot_overshoot', (string)($intent['symbol'] ?? ''), [
                'qty_min'      => $qtyMin,
                'entry_ref'    => $entryRef,
                'min_lot_cost' => $minLotCost,
                'unleveraged'  => $unleveraged,
                'allowed_max'  => $allowedMax,
                'overshoot_pct'=> $overshootPct,
            ]);

            return [[
                'guard'    => 'min_lot_overshoot',
                'message'  => sprintf(
                    'Минимальный лот %.4f монет × %.6f = %.4f USDT > допустимого %.4f (%.1f%% превышение)',
                    $qtyMin, $entryRef, $minLotCost, $allowedMax, $overshootPct
                ),
                'critical' => false,
            ]];
        }

        return [];
    }
}
