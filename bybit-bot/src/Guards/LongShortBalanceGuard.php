<?php
declare(strict_types=1);

namespace BybitBot\Guards;

use BybitBot\Core\Config;
use BybitBot\Core\EventRecorder;

/**
 * Защита «баланс long/short» (long_short_balance). См. spec.md §9.
 *
 * Запрещает открывать в направлении, если его доля превышает max_share_pct.
 * Проверяется только если total_orders >= min_total_to_check (по умолчанию 6).
 *
 * Настройки:
 *   long_short_balance.enabled           (bool, default false)
 *   long_short_balance.max_share_pct     (float, default 70.0)
 *   long_short_balance.min_total_to_check (int, default 6)
 */
final class LongShortBalanceGuard implements GuardsInterface
{
    public function check(array $intent, array $context): array
    {
        $stratId = (string)($intent['strategy_id'] ?? null);
        $enabled = filter_var(
            Config::get('long_short_balance.enabled', $stratId ?: null, false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$enabled) {
            return [];
        }

        $maxSharePct    = (float)Config::get('long_short_balance.max_share_pct',       $stratId ?: null, 70.0);
        $minTotalCheck  = (int)  Config::get('long_short_balance.min_total_to_check',  $stratId ?: null, 6);

        // Считаем текущий баланс long/short из контекста
        $positions = (array)($context['positions'] ?? []);
        $orders    = (array)($context['open_orders'] ?? []);

        $longCount  = 0;
        $shortCount = 0;

        foreach ($positions as $pos) {
            $side = (string)($pos['side'] ?? $pos['positionSide'] ?? '');
            if ($side === 'Buy' || $side === 'Long' || $side === 'long') {
                $longCount++;
            } elseif ($side === 'Sell' || $side === 'Short' || $side === 'short') {
                $shortCount++;
            }
        }

        foreach ($orders as $ord) {
            $side = (string)($ord['side'] ?? '');
            // conditional ордера: Buy=long, Sell=short
            if ($side === 'Buy') {
                $longCount++;
            } elseif ($side === 'Sell') {
                $shortCount++;
            }
        }

        $total = $longCount + $shortCount;

        // Проверка только при достаточном количестве
        if ($total < $minTotalCheck) {
            return [];
        }

        $intendedSide = (string)($intent['side'] ?? 'long');
        $isLong       = ($intendedSide === 'long' || $intendedSide === 'Buy');

        if ($isLong) {
            $shareAfter = (($longCount + 1) / ($total + 1)) * 100.0;
        } else {
            $shareAfter = (($shortCount + 1) / ($total + 1)) * 100.0;
        }

        if ($shareAfter > $maxSharePct) {
            EventRecorder::event(EventRecorder::WARN, 'guard_long_short_balance', null, [
                'side'        => $intendedSide,
                'long_count'  => $longCount,
                'short_count' => $shortCount,
                'total'       => $total,
                'share_after' => round($shareAfter, 1),
                'max_share'   => $maxSharePct,
            ]);

            return [[
                'guard'    => 'long_short_balance',
                'message'  => sprintf(
                    'Дисбаланс long/short: %s достигнет %.1f%% (лимит %.1f%%). Long=%d, Short=%d, Total=%d',
                    $isLong ? 'long' : 'short',
                    $shareAfter, $maxSharePct,
                    $longCount, $shortCount, $total
                ),
                'critical' => false,
            ]];
        }

        return [];
    }
}
