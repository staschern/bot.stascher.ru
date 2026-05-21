<?php
declare(strict_types=1);

namespace BybitBot\Trade;

use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\Logger;

/**
 * EquitySnapshotsService — снимки депозита на начало периода.
 *
 * v0.9.0-step9 task4.
 *
 * Используется на странице /stats, чтобы показывать «депозит на начало
 * недели/месяца» и считать PnL % за период от него.
 *
 * Источник истины (в порядке предпочтения):
 *   1. equity_snapshots — если запись на этот период уже есть.
 *   2. backfill — вычисляем из закрытых trades:
 *        deposit_start(period) = D0 + sum(realized_pnl_usdt)
 *                                для closed_at < начало периода.
 *
 * Здесь все периоды считаются в UTC. Для week принят понедельник как
 * начало недели (соответствие тому, как мы отображаем диапазон
 * «пн … вс»). period_start всегда YYYY-MM-DD.
 */
final class EquitySnapshotsService
{
    /**
     * Получить понедельник недели (UTC), к которой принадлежит дата $ymd
     * (формат YYYY-MM-DD). Возвращает YYYY-MM-DD.
     */
    public static function mondayOf(string $ymd): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $ymd, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return $ymd;
        }
        // ISO-неделя: 1 = пн, 7 = вс
        $dow = (int)$dt->format('N');
        if ($dow > 1) {
            $dt->modify('-' . ($dow - 1) . ' days');
        }
        return $dt->format('Y-m-d');
    }

    /**
     * Первое число месяца, к которому принадлежит дата $ymd.
     */
    public static function firstOfMonth(string $ymd): string
    {
        return substr($ymd, 0, 7) . '-01';
    }

    /**
     * Текущий период (YYYY-MM-DD начала) по типу 'week'|'month' для now UTC.
     */
    public static function currentPeriodStart(string $periodType): string
    {
        $today = gmdate('Y-m-d');
        return $periodType === 'month'
            ? self::firstOfMonth($today)
            : self::mondayOf($today);
    }

    /**
     * Сумма realized_pnl_usdt по закрытым сделкам режима в интервале [from, to).
     * Если $from === null — без нижней границы. Если $to === null — без верхней.
     * Границы в формате YYYY-MM-DD (UTC).
     */
    public static function sumRealizedPnl(string $mode, ?int $accountId, ?string $fromYmd, ?string $toYmd): float
    {
        $pdo  = Database::pdo();
        $sql  = "SELECT COALESCE(SUM(realized_pnl_usdt), 0) AS s
                  FROM trades
                 WHERE mode = :mode
                   AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
                   AND closed_at IS NOT NULL";
        $bind = [':mode' => $mode];
        if ($fromYmd !== null) {
            $sql .= ' AND closed_at >= :from_iso';
            $bind[':from_iso'] = $fromYmd . 'T00:00:00Z';
        }
        if ($toYmd !== null) {
            $sql .= ' AND closed_at < :to_iso';
            $bind[':to_iso'] = $toYmd . 'T00:00:00Z';
        }
        if ($accountId !== null) {
            $sql .= ' AND account_id = :acc';
            $bind[':acc'] = $accountId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Текущий wallet/equity аккаунта.
     *  - paper             → paper_initial_deposit_usdt (фикс D0).
     *  - paper + accountId → NaN (не должно вызываться, в paper per-acc нет смысла).
     *  - testnet/live + accountId → wallet этого аккаунта через API.
     *  - testnet/live (агрегат) → сумма wallet по всем enabled аккаунтам режима.
     *
     * Для вычисления исторического deposit_start вычитаем сумму realized PnL
     * с начала периода — это даёт приближённый wallet на начало периода.
     */
    public static function currentWallet(string $mode, ?int $accountId): float
    {
        if ($mode === 'paper') {
            if ($accountId !== null) {
                // paper per-account не имеет собственного D0 — возвращаем NAN-marker.
                return NAN;
            }
            return (float)Config::get('paper_initial_deposit_usdt', null, 300.0)
                 + self::sumRealizedPnl($mode, null, null, null);
        }
        // testnet/live
        if ($accountId !== null) {
            return EquityService::fetchLiveWalletForAccount($accountId, $mode);
        }
        // агрегат по enabled аккаунтам режима
        $total = 0.0;
        try {
            foreach (BybitAccountsRepo::getEnabledForNetwork($mode) as $a) {
                $total += EquityService::fetchLiveWalletForAccount((int)$a['id'], $mode);
            }
        } catch (\Throwable $e) {
            Logger::get()->warning('EquitySnapshots: aggregate wallet failed', [
                'mode' => $mode, 'error' => $e->getMessage(),
            ]);
        }
        return $total;
    }

    /**
     * Приближённый deposit_start на начало периода.
     *
     * Формула: current_wallet − sum(realized_pnl с closed_at ≥ periodStart).
     * Для прошлых периодов это даёт wallet на момент начала периода —
     * без учёта внешних вводов/выводов средств (их у нас в боте нет) и floating PnL.
     *
     * paper + accountId → NAN.
     */
    public static function computeStartFromTrades(string $mode, ?int $accountId, string $periodStart): float
    {
        $wallet = self::currentWallet($mode, $accountId);
        if (is_nan($wallet)) {
            return NAN;
        }
        $pnlAfter = self::sumRealizedPnl($mode, $accountId, $periodStart, null);
        return $wallet - $pnlAfter;
    }

    /**
     * Сохранить snapshot (INSERT OR IGNORE — если уже есть, не трогаем).
     * Если $force=true — заменяем (UPSERT).
     */
    public static function saveSnapshot(
        string $mode,
        ?int $accountId,
        string $periodType,
        string $periodStart,
        float $deposit,
        string $source = 'cron_daily',
        bool $force = false
    ): bool {
        // NAN = невозможно вычислить (напр. paper per-account) — не сохраняем.
        if (!is_finite($deposit)) {
            return false;
        }
        $pdo = Database::pdo();
        $createdAt = gmdate('Y-m-d\TH:i:s\Z');

        // SQLite: проверка существования по уникальному ключу.
        $sel = $pdo->prepare(
            'SELECT id FROM equity_snapshots
             WHERE mode = :m
               AND period_type = :pt
               AND period_start = :ps
               AND (
                    (:acc IS NULL AND account_id IS NULL)
                 OR (account_id = :acc)
               )
             LIMIT 1'
        );
        $sel->execute([
            ':m'   => $mode,
            ':pt'  => $periodType,
            ':ps'  => $periodStart,
            ':acc' => $accountId,
        ]);
        $existing = $sel->fetch(\PDO::FETCH_ASSOC);

        if ($existing !== false) {
            if (!$force) {
                return false;
            }
            $upd = $pdo->prepare(
                'UPDATE equity_snapshots
                    SET deposit_usdt = :dep,
                        source       = :src,
                        created_at   = :ca
                  WHERE id = :id'
            );
            $upd->execute([
                ':dep' => $deposit,
                ':src' => $source,
                ':ca'  => $createdAt,
                ':id'  => (int)$existing['id'],
            ]);
            return true;
        }

        $ins = $pdo->prepare(
            'INSERT INTO equity_snapshots
                (mode, account_id, period_type, period_start, deposit_usdt, source, created_at)
             VALUES
                (:m, :acc, :pt, :ps, :dep, :src, :ca)'
        );
        $ins->execute([
            ':m'   => $mode,
            ':acc' => $accountId,
            ':pt'  => $periodType,
            ':ps'  => $periodStart,
            ':dep' => $deposit,
            ':src' => $source,
            ':ca'  => $createdAt,
        ]);
        return true;
    }

    /**
     * Получить deposit_start для периода: сначала из таблицы snapshots, при отсутствии —
     * вычисляем из trades (но не сохраняем — это lazy lookup для отображения).
     */
    public static function getDepositStart(string $mode, ?int $accountId, string $periodType, string $periodStart): float
    {
        // paper + per-account: возвращаем NAN (UI покажет —).
        if ($mode === 'paper' && $accountId !== null) {
            return NAN;
        }
        $pdo = Database::pdo();
        $sel = $pdo->prepare(
            'SELECT deposit_usdt FROM equity_snapshots
             WHERE mode = :m
               AND period_type = :pt
               AND period_start = :ps
               AND (
                    (:acc IS NULL AND account_id IS NULL)
                 OR (account_id = :acc)
               )
             LIMIT 1'
        );
        $sel->execute([
            ':m'   => $mode,
            ':pt'  => $periodType,
            ':ps'  => $periodStart,
            ':acc' => $accountId,
        ]);
        $row = $sel->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false) {
            return (float)$row['deposit_usdt'];
        }
        return self::computeStartFromTrades($mode, $accountId, $periodStart);
    }

    /**
     * Принудительное сохранение снапшота текущего периода (v0.9.1).
     *
     * Аналог snapshotDaily(), но с force=true — перезаписывает существующее значение.
     * Используется кнопкой «Обновить снапшот» на /stats, чтобы не ждать cron_daily.
     *
     * @return array{written:int, skipped:int}
     */
    public static function snapshotNow(): array
    {
        $modes = ['paper', 'testnet', 'live'];
        $weekStart  = self::mondayOf(gmdate('Y-m-d'));
        $monthStart = self::firstOfMonth(gmdate('Y-m-d'));

        $written = 0;
        $skipped = 0;

        foreach ($modes as $mode) {
            $depAgg = self::currentWallet($mode, null);
            if (is_finite($depAgg)) {
                foreach (['week' => $weekStart, 'month' => $monthStart] as $pt => $ps) {
                    if (self::saveSnapshot($mode, null, $pt, $ps, $depAgg, 'manual_refresh', true)) {
                        $written++;
                    } else {
                        $skipped++;
                    }
                }
            }
            if ($mode !== 'paper') {
                try {
                    $accounts = BybitAccountsRepo::getEnabledForNetwork($mode);
                } catch (\Throwable $e) {
                    $accounts = [];
                }
                foreach ($accounts as $a) {
                    $aid  = (int)$a['id'];
                    $depA = self::currentWallet($mode, $aid);
                    if (!is_finite($depA)) { continue; }
                    foreach (['week' => $weekStart, 'month' => $monthStart] as $pt => $ps) {
                        if (self::saveSnapshot($mode, $aid, $pt, $ps, $depA, 'manual_refresh', true)) {
                            $written++;
                        } else {
                            $skipped++;
                        }
                    }
                }
            }
        }
        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * Ежедневный snapshot (вызывается из cron_daily на 00:00 UTC).
     * Сохраняем:
     *   - week-snapshot на понедельник текущей недели (UTC) — если ещё нет;
     *   - month-snapshot на 1-е число текущего месяца — если ещё нет.
     *
     * Идемпотентно: повторный вызов не перезаписывает существующие.
     *
     * @return array{written:int, skipped:int, modes:array<string,int>}
     */
    public static function snapshotDaily(): array
    {
        $modes = ['paper', 'testnet', 'live'];
        $weekStart  = self::mondayOf(gmdate('Y-m-d'));
        $monthStart = self::firstOfMonth(gmdate('Y-m-d'));

        $written = 0;
        $skipped = 0;
        $perMode = [];

        foreach ($modes as $mode) {
            $localWritten = 0;
            // Агрегат по всем аккаунтам (account_id NULL).
            // wallet — это и есть депозит на начало периода (снапшот в 00:00 UTC),
            // потому что на этот момент PnL внутри периода ещё нулевой.
            $depAgg = self::currentWallet($mode, null);
            foreach (['week' => $weekStart, 'month' => $monthStart] as $pt => $ps) {
                if (self::saveSnapshot($mode, null, $pt, $ps, $depAgg, 'cron_daily', false)) {
                    $written++;
                    $localWritten++;
                } else {
                    $skipped++;
                }
            }

            // Per-account (только для testnet/live; paper всегда один аккаунт).
            if ($mode !== 'paper') {
                try {
                    $accounts = BybitAccountsRepo::getEnabledForNetwork($mode);
                } catch (\Throwable $e) {
                    $accounts = [];
                }
                foreach ($accounts as $a) {
                    $aid = (int)$a['id'];
                    $depA = self::currentWallet($mode, $aid);
                    if (!is_finite($depA)) { continue; }
                    foreach (['week' => $weekStart, 'month' => $monthStart] as $pt => $ps) {
                        if (self::saveSnapshot($mode, $aid, $pt, $ps, $depA, 'cron_daily', false)) {
                            $written++;
                            $localWritten++;
                        } else {
                            $skipped++;
                        }
                    }
                }
            }
            $perMode[$mode] = $localWritten;
        }
        return ['written' => $written, 'skipped' => $skipped, 'modes' => $perMode];
    }

    /**
     * Backfill: для каждого периода (week/month) во всех модах
     * пересчитать deposit_start ретроспективно из истории trades.
     *
     * Идём от первой закрытой сделки до текущего периода. force=true перезаписывает.
     *
     * @return array{written:int, periods:array<int,array{mode:string,type:string,start:string,deposit:float}>}
     */
    public static function backfillAll(bool $force = true): array
    {
        $pdo   = Database::pdo();
        $modes = ['paper', 'testnet', 'live'];
        $written = 0;
        $periods = [];

        foreach ($modes as $mode) {
            // Найти диапазон closed_at в этом моде.
            $st = $pdo->prepare(
                "SELECT MIN(date(closed_at)) AS d_min, MAX(date(closed_at)) AS d_max
                   FROM trades
                  WHERE mode = :m
                    AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
                    AND closed_at IS NOT NULL"
            );
            $st->execute([':m' => $mode]);
            $r = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$r || !$r['d_min']) {
                continue;
            }
            $dMin = (string)$r['d_min'];
            $dMax = (string)$r['d_max'];

            // Список аккаунтов для этого режима (для per-account snapshot'ов).
            $accounts = [null]; // null = aggregate
            if ($mode !== 'paper') {
                try {
                    $accRows = BybitAccountsRepo::listAll(false);
                    foreach ($accRows as $a) {
                        // Берём аккаунт, если у него есть хотя бы одна закрытая сделка в этом режиме.
                        $chk = $pdo->prepare(
                            "SELECT 1 FROM trades
                              WHERE mode = :m AND account_id = :acc
                                AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
                              LIMIT 1"
                        );
                        $chk->execute([':m' => $mode, ':acc' => (int)$a['id']]);
                        if ($chk->fetch() !== false) {
                            $accounts[] = (int)$a['id'];
                        }
                    }
                } catch (\Throwable $e) {
                    // continue with aggregate only
                }
            }

            // Перечисляем недели от mondayOf(dMin) до mondayOf(today) включительно.
            $todayStr = gmdate('Y-m-d');
            // weeks
            $cur = self::mondayOf($dMin);
            $end = self::mondayOf($todayStr);
            $i = 0;
            while (strcmp($cur, $end) <= 0 && $i < 520) {
                foreach ($accounts as $acc) {
                    $dep = self::computeStartFromTrades($mode, $acc, $cur);
                    $ok = self::saveSnapshot($mode, $acc, 'week', $cur, $dep, 'backfill', $force);
                    if ($ok) {
                        $written++;
                        if (count($periods) < 200) {
                            $periods[] = [
                                'mode' => $mode, 'acc' => $acc,
                                'type' => 'week', 'start' => $cur, 'deposit' => $dep,
                            ];
                        }
                    }
                }
                $cur = (new \DateTime($cur, new \DateTimeZone('UTC')))
                    ->modify('+7 days')->format('Y-m-d');
                $i++;
            }
            // months
            $curM = self::firstOfMonth($dMin);
            $endM = self::firstOfMonth($todayStr);
            $j = 0;
            while (strcmp($curM, $endM) <= 0 && $j < 240) {
                foreach ($accounts as $acc) {
                    $dep = self::computeStartFromTrades($mode, $acc, $curM);
                    $ok = self::saveSnapshot($mode, $acc, 'month', $curM, $dep, 'backfill', $force);
                    if ($ok) {
                        $written++;
                        if (count($periods) < 400) {
                            $periods[] = [
                                'mode' => $mode, 'acc' => $acc,
                                'type' => 'month', 'start' => $curM, 'deposit' => $dep,
                            ];
                        }
                    }
                }
                $curM = (new \DateTime($curM, new \DateTimeZone('UTC')))
                    ->modify('+1 month')->format('Y-m-d');
                $j++;
            }
        }
        return ['written' => $written, 'periods' => $periods];
    }
}
