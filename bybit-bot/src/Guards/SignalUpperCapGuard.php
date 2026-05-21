<?php
declare(strict_types=1);

namespace BybitBot\Guards;

use BybitBot\Core\Config;
use BybitBot\Core\EventRecorder;

/**
 * Защита «верхний порог сигнала» (signal_upper_cap). См. spec.md §9.
 *
 * Игнорирует сигналы, у которых |target| > max_target_pct.
 *
 * Настройки:
 *   signal_upper_cap.enabled     (bool, default false)
 *   signal_upper_cap.pct         (float, default 10.0)
 */
final class SignalUpperCapGuard implements GuardsInterface
{
    public function check(array $intent, array $context): array
    {
        $stratId = (string)($intent['strategy_id'] ?? null);
        $enabled = filter_var(
            Config::get('signal_upper_cap.enabled', $stratId ?: null, false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$enabled) {
            return [];
        }

        $maxPct    = (float)Config::get('signal_upper_cap.pct', $stratId ?: null, 10.0);
        $targetPct = isset($intent['target_pct']) ? abs((float)$intent['target_pct']) : 0.0;

        if ($targetPct > $maxPct) {
            EventRecorder::event(EventRecorder::INFO, 'guard_signal_upper_cap', (string)($intent['symbol'] ?? ''), [
                'target_pct' => $targetPct,
                'max_pct'    => $maxPct,
            ]);

            return [[
                'guard'    => 'signal_upper_cap',
                'message'  => sprintf(
                    'Сигнал %.2f%% превышает верхний порог %.2f%%',
                    $targetPct, $maxPct
                ),
                'critical' => false,
            ]];
        }

        return [];
    }
}
