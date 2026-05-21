<?php
declare(strict_types=1);

namespace BybitBot\Guards;

use BybitBot\Core\Config;
use BybitBot\Core\EventRecorder;

/**
 * Защита «funding filter» (funding_filter). См. spec.md §9.
 *
 * Не открывает позицию, если ставка финансирования экстремальная
 * и направлена против нашей позиции.
 *
 * Настройки:
 *   funding_filter.enabled          (bool, default false)
 *   funding_filter.extreme_pct      (float, default 0.1)
 */
final class FundingFilterGuard implements GuardsInterface
{
    public function check(array $intent, array $context): array
    {
        $stratId = (string)($intent['strategy_id'] ?? null);
        $enabled = filter_var(
            Config::get('funding_filter.enabled', $stratId ?: null, false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$enabled) {
            return [];
        }

        $extremePct = (float)Config::get('funding_filter.extreme_pct', $stratId ?: null, 0.1);

        // Ставка финансирования должна быть в контексте
        $fundingRate = isset($context['funding_rate']) ? (float)$context['funding_rate'] : null;
        if ($fundingRate === null) {
            return []; // Нет данных — пропускаем проверку
        }

        $side  = (string)($intent['side'] ?? 'long');
        $isLong = ($side === 'long' || $side === 'Buy');

        // Если funding > extreme_pct: платят long-позиции (значит нам не выгодно быть long)
        // Если funding < -extreme_pct: платят short-позиции
        $fundingAbsPct = abs($fundingRate) * 100.0;

        if ($fundingAbsPct < $extremePct) {
            return []; // Финансирование в норме
        }

        // Проверяем направление
        $fundingHurtsUs = false;
        if ($isLong && $fundingRate > 0) {
            // Экстремальный positive funding — платим за long
            $fundingHurtsUs = true;
        } elseif (!$isLong && $fundingRate < 0) {
            // Экстремальный negative funding — платим за short
            $fundingHurtsUs = true;
        }

        if ($fundingHurtsUs) {
            EventRecorder::event(EventRecorder::WARN, 'guard_funding_filter', (string)($intent['symbol'] ?? ''), [
                'side'         => $side,
                'funding_rate' => $fundingRate,
                'extreme_pct'  => $extremePct,
            ]);

            return [[
                'guard'    => 'funding_filter',
                'message'  => sprintf(
                    'Экстремальный funding %.4f%% против %s (лимит %.4f%%)',
                    $fundingRate * 100, $side, $extremePct
                ),
                'critical' => false,
            ]];
        }

        return [];
    }
}
