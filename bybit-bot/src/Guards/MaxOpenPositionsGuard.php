<?php
declare(strict_types=1);

namespace BybitBot\Guards;

use BybitBot\Core\Config;
use BybitBot\Core\EventRecorder;

/**
 * Защита «максимальное число открытых позиций» (max_open_positions). См. spec.md §9 и §4.2.
 *
 * Настройки:
 *   max_open_positions (int, default 15)
 */
final class MaxOpenPositionsGuard implements GuardsInterface
{
    public function check(array $intent, array $context): array
    {
        $stratId  = (string)($intent['strategy_id'] ?? null);
        $maxOpen  = (int)Config::get('max_open_positions', $stratId ?: null, 15);
        $positions = (array)($context['positions'] ?? []);

        $openCount = 0;
        foreach ($positions as $pos) {
            // В paper-режиме positions из paper_positions; в реальном — из Bybit
            $size = isset($pos['size']) ? (float)$pos['size'] : (float)($pos['qty'] ?? 0);
            if ($size > 0) {
                $openCount++;
            }
        }

        if ($openCount >= $maxOpen) {
            EventRecorder::event(EventRecorder::WARN, 'guard_max_open_positions', null, [
                'open_count' => $openCount,
                'max'        => $maxOpen,
                'strategy_id'=> $stratId,
            ]);

            return [[
                'guard'    => 'max_open_positions',
                'message'  => sprintf(
                    'Достигнут лимит открытых позиций: %d >= %d',
                    $openCount, $maxOpen
                ),
                'critical' => true,
            ]];
        }

        return [];
    }
}
