<?php
declare(strict_types=1);

namespace BybitBot\Trade;

use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\Logger;
use BybitBot\Exchange\AdapterFactory;

/**
 * EquityService — расчёт текущего эквити депозита для отображения на /trades.
 *
 * См. spec.md §14.5-14.6 (v0.7.0).
 *
 * Формула (paper):
 *   D0          = paper_initial_deposit_usdt (по умолчанию 300)
 *   margin_open = sum( (entry_real * qty_initial) / leverage )   по открытым позициям
 *   realized    = sum( realized_pnl_usdt )                       по закрытым trades данного режима
 *   wallet      = D0 - margin_open + realized
 *   floating    = sum( (last_price - entry_real) * qty_initial * sign )  по открытым позициям
 *                 - ожидаемые комиссии закрытия (2 * taker_fee * notional)
 *   equity      = wallet + floating
 *
 * Формула (testnet/live):
 *   Берём из последнего deposit_snapshots(mode) + наш расчёт floating (как для paper).
 *   В будущем — заменить на API /v5/account/wallet-balance (totalEquity).
 *
 * Цвет (по ТЗ 14.6 — Текущий / D0):
 *   ≥0.8  → green
 *   0.5..0.8  → light-green
 *   0.3..0.5  → yellow
 *   0.1..0.3  → light-red
 *   <0.1  → red
 */
final class EquityService
{
    /**
     * @param string   $mode      'paper'|'testnet'|'live'
     * @param int|null $accountId v0.9.0-step6: если задан — возвращаем эквити только
     *                            для этого аккаунта (testnet/live). Для paper игнорируется.
     *
     * @return array{
     *   equity: float, d0: float, wallet: float, floating_pnl: float,
     *   realized: float, margin_open: float, ratio: float, color: string,
     *   open_count: int
     * }
     */
    public static function computeEquity(string $mode, ?int $accountId = null): array
    {
        $pdo = Database::pdo();

        // v0.9.0-step6: фильтр по аккаунту влияет только на testnet/live.
        $hasAccFilter = ($accountId !== null) && ($mode === 'testnet' || $mode === 'live');

        // 1) D0 — стартовый депозит
        if ($mode === 'paper') {
            $d0 = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
        } elseif ($hasAccFilter) {
            // testnet/live + конкретный аккаунт: баланс берём с биржи по его ключам.
            $d0 = self::fetchLiveWalletForAccount($accountId, $mode);
        } else {
            // testnet/live + все аккаунты: v0.9.0-step6.1 — сумма по enabled аккаунтам.
            // Каждый аккаунт спрашиваем напрямую через AdapterFactory::forAccount().
            // Если по какому-то аккаунту запрос упал — пишем warn и пропускаем
            // (D0 будет занижен, но не сломан).
            $d0 = 0.0;
            $enabled = [];
            try {
                $enabled = BybitAccountsRepo::getEnabledForNetwork($mode);
            } catch (\Throwable $e) {
                Logger::get()->warning('EquityService: getEnabledForNetwork failed', [
                    'mode'  => $mode,
                    'error' => $e->getMessage(),
                ]);
            }

            if (count($enabled) > 0) {
                foreach ($enabled as $a) {
                    $aid = (int)$a['id'];
                    $bal = self::fetchLiveWalletForAccount($aid, $mode);
                    $d0 += $bal;
                }
            } else {
                // Фолбэк на старое поведение: один общий snapshot.
                // Используется, если репозиторий пуст или getEnabledForNetwork недоступен.
                $stmt = $pdo->prepare(
                    'SELECT value FROM deposit_snapshots WHERE mode = :m ORDER BY ts ASC LIMIT 1'
                );
                $stmt->execute([':m' => $mode]);
                $row = $stmt->fetch();
                $d0  = $row !== false ? (float)$row['value'] : 0.0;
            }
        }

        // 2) Открытые позиции данного режима — для margin и floating PnL.
        // v0.9.0-step6: если задан account_id — в выборку входят только позиции этого аккаунта.
        $sql = "SELECT p.qty_initial, p.avg_entry_price, p.leverage, p.side, p.last_price,
                       t.entry_real, t.qty_initial AS trade_qty_initial
                FROM positions p
                JOIN trades t ON t.id = p.trade_id
                WHERE p.exchange = :mode AND p.closed_at IS NULL";
        $bind = [':mode' => $mode];
        if ($hasAccFilter) {
            $sql .= " AND t.account_id = :acc";
            $bind[':acc'] = $accountId;
        } elseif ($mode !== 'paper') {
            // Не учитываем позиции выключенных аккаунтов в общем расчёте.
            $sql .= " AND (t.account_id IS NULL OR t.account_id IN (SELECT id FROM bybit_accounts WHERE enabled = 1 AND archived_at IS NULL))";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $openRows = $stmt->fetchAll();

        $takerFeePct = (float)Config::get('taker_fee_pct', null, 0.055);

        $marginOpen  = 0.0;
        $floatingPnl = 0.0;
        $openCount   = 0;

        foreach ($openRows as $r) {
            $qty1     = (float)($r['trade_qty_initial'] ?? $r['qty_initial']);
            $entry    = (float)($r['entry_real'] ?? $r['avg_entry_price']);
            $leverage = (float)($r['leverage'] ?: 1);
            $sign     = ((string)$r['side'] === 'Buy') ? 1.0 : -1.0;
            $last     = $r['last_price'] !== null ? (float)$r['last_price'] : null;

            if ($qty1 <= 0 || $entry <= 0 || $leverage <= 0) {
                continue;
            }
            $openCount++;

            // Маржа первого лота
            $marginOpen += ($entry * $qty1) / $leverage;

            // Плавающий PnL (только если знаем текущую цену)
            if ($last !== null && $last > 0) {
                $pnl = ($last - $entry) * $qty1 * $sign;
                // Ожидаемые комиссии: закрытие лота (taker) — учитываем как "обязательство"
                $closeFee = $last * $qty1 * ($takerFeePct / 100.0);
                $floatingPnl += $pnl - $closeFee;
            }
        }

        // 3) Realized PnL — сумма по закрытым сделкам данного режима.
        // v0.9.0-step6: фильтр по account_id для testnet/live.
        $sqlR = "SELECT COALESCE(SUM(realized_pnl_usdt), 0) AS s
                 FROM trades
                 WHERE mode = :mode AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')";
        $bindR = [':mode' => $mode];
        if ($hasAccFilter) {
            $sqlR .= " AND account_id = :acc";
            $bindR[':acc'] = $accountId;
        } elseif ($mode !== 'paper') {
            // Не учитываем PnL выключенных аккаунтов в общем расчёте.
            $sqlR .= " AND (account_id IS NULL OR account_id IN (SELECT id FROM bybit_accounts WHERE enabled = 1 AND archived_at IS NULL))";
        }
        $stmt = $pdo->prepare($sqlR);
        $stmt->execute($bindR);
        $realized = (float)$stmt->fetchColumn();

        $wallet = $d0 - $marginOpen + $realized;
        $equity = $wallet + $floatingPnl;

        $ratio = $d0 > 0 ? ($equity / $d0) : 0.0;
        $color = self::colorForRatio($ratio);

        return [
            'equity'       => round($equity, 4),
            'd0'           => round($d0, 4),
            'wallet'       => round($wallet, 4),
            'floating_pnl' => round($floatingPnl, 4),
            'realized'     => round($realized, 4),
            'margin_open'  => round($marginOpen, 4),
            'ratio'        => round($ratio, 4),
            'color'        => $color,
            'open_count'   => $openCount,
        ];
    }

    /**
     * v0.9.0-step6.1: получить totalWalletBalance с биржи для конкретного аккаунта.
     * При ошибке — пишет warn и возвращает 0.0 (не валит расчёт всего эквити).
     *
     * @param int    $accountId
     * @param string $mode  только для логов (testnet|live)
     */
    public static function fetchLiveWalletForAccount(int $accountId, string $mode): float
    {
        try {
            $adapter = AdapterFactory::forAccount($accountId);
            $bal     = $adapter->getWalletBalance();
            return (float)($bal['totalWalletBalance'] ?? 0.0);
        } catch (\Throwable $e) {
            Logger::get()->warning('EquityService: getWalletBalance failed', [
                'mode'       => $mode,
                'account_id' => $accountId,
                'error'      => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    /**
     * Цвет по ТЗ §14.6 (Текущий / D0).
     */
    public static function colorForRatio(float $ratio): string
    {
        if ($ratio >= 0.8) return 'green';
        if ($ratio >= 0.5) return 'light-green';
        if ($ratio >= 0.3) return 'yellow';
        if ($ratio >= 0.1) return 'light-red';
        return 'red';
    }
}
