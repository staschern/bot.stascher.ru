<?php
declare(strict_types=1);

namespace BybitBot\Guards;

use BybitBot\Core\Config;
use BybitBot\Core\EventRecorder;

/**
 * Защита «максимальное число ордеров+позиций» (max_total_orders_with_pending). См. spec.md §9, §4.2.
 *
 * Если суммарное число (открытые позиции + pending conditional) >= max_total_orders_with_pending
 * и наших conditional нет — блокируем.
 *
 * v0.9.0-step7 §3: если в context переданы 'our_trades_open_count' и 'our_trades_pending_count',
 * считаем ПО НИМ (наши trades в базе), а не по exchange positions/orders.
 * Причина: Bybit V5 UNIFIED может возвращать по ключам разных субаккаунтов один и тот же
 * список, из-за чего fan-out срывается вторым и последующим аккаунтам. cron_hourly передаёт
 * эти поля всегда; легаси-вызовы без этих полей используют старое поведение.
 *
 * Настройки:
 *   max_total_orders_with_pending (int, default 20)
 */
final class MaxTotalOrdersGuard implements GuardsInterface
{
    public function check(array $intent, array $context): array
    {
        $stratId   = (string)($intent['strategy_id'] ?? null);
        $maxTotal  = (int)Config::get('max_total_orders_with_pending', $stratId ?: null, 20);

        // v0.9.0-step7 §3: предпочитаем подсчёт по нашим trades из context, если он передан.
        $hasOurCounts = array_key_exists('our_trades_open_count', $context)
                     && array_key_exists('our_trades_pending_count', $context);

        if ($hasOurCounts) {
            $openCount    = (int)$context['our_trades_open_count'];
            $pendingCount = (int)$context['our_trades_pending_count'];
            $source       = 'our_trades';
        } else {
            $positions = (array)($context['positions'] ?? []);
            $orders    = (array)($context['open_orders'] ?? []);

            $openCount    = 0;
            $pendingCount = 0;

            foreach ($positions as $pos) {
                $size = isset($pos['size']) ? (float)$pos['size'] : (float)($pos['qty'] ?? 0);
                if ($size > 0) {
                    $openCount++;
                }
            }
            foreach ($orders as $ord) {
                $pendingCount++;
            }
            $source = 'exchange';
        }

        $total = $openCount + $pendingCount;

        if ($total >= $maxTotal) {
            EventRecorder::event(EventRecorder::WARN, 'guard_max_total_orders', null, [
                'open_count'    => $openCount,
                'pending_count' => $pendingCount,
                'total'         => $total,
                'max'           => $maxTotal,
                'strategy_id'   => $stratId,
                'account_id'    => $context['account_id'] ?? null,
                'source'        => $source,
            ]);

            return [[
                'guard'    => 'max_total_orders_with_pending',
                'message'  => sprintf(
                    'Лимит позиций+conditional: %d >= %d (open=%d, pending=%d)',
                    $total, $maxTotal, $openCount, $pendingCount
                ),
                'critical' => true,
            ]];
        }

        return [];
    }
}
