<?php
declare(strict_types=1);

namespace BybitBot\Guards;

use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;

/**
 * Защита «дневной стоп» (daily_drawdown). См. spec.md §9.
 *
 * Блокирует новые ордера, если текущий баланс просел на X% относительно
 * deposit_anchor (зафиксированного на начало дня).
 *
 * Настройки:
 *   daily_drawdown.enabled  (bool, default false)
 *   daily_drawdown.pct      (float, default 10.0)
 */
final class DailyDrawdownGuard implements GuardsInterface
{
    public function check(array $intent, array $context): array
    {
        $stratId  = (string)($intent['strategy_id'] ?? null);
        $enabled  = filter_var(
            Config::get('daily_drawdown.enabled', $stratId ?: null, false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$enabled) {
            return [];
        }

        $pct             = (float)Config::get('daily_drawdown.pct', $stratId ?: null, 10.0);
        $depositAnchor   = (float)($context['deposit_anchor'] ?? 0);
        $currentBalance  = (float)($context['current_balance'] ?? $depositAnchor);

        if ($depositAnchor <= 0) {
            return [];
        }

        $drawdownPct = (($depositAnchor - $currentBalance) / $depositAnchor) * 100.0;

        if ($drawdownPct >= $pct) {
            EventRecorder::event(EventRecorder::WARN, 'guard_daily_drawdown', null, [
                'drawdown_pct'   => round($drawdownPct, 2),
                'threshold_pct'  => $pct,
                'deposit_anchor' => $depositAnchor,
                'current_balance'=> $currentBalance,
                'strategy_id'    => $stratId,
            ]);

            return [[
                'guard'    => 'daily_drawdown',
                'message'  => sprintf(
                    'Дневной стоп: просадка %.2f%% >= %.2f%% (депозит %.2f → %.2f USDT)',
                    $drawdownPct, $pct, $depositAnchor, $currentBalance
                ),
                'critical' => true,
            ]];
        }

        return [];
    }
}
