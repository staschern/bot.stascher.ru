<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use BybitBot\Trade\EquityService;
use BybitBot\Trade\EquitySnapshotsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * StatsController — вкладка «Результаты» (v0.9.2).
 *
 * GET /stats?mode=paper — таблицы PnL по неделям и месяцам + график equity.
 * Поддерживает сравнение s1 vs s2 (переключатель в UI).
 */
final class StatsController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $twig    = Twig::fromRequest($request);
        $pdo     = Database::pdo();
        $params  = $request->getQueryParams();

        $currentMode = (string)Config::get('mode', null, 'paper');
        $mode        = isset($params['mode']) && $params['mode'] !== '' ? (string)$params['mode'] : $currentMode;

        $filterAccountRaw = isset($params['account']) ? (string)$params['account'] : '';
        $filterAccount    = ($filterAccountRaw !== '' && ctype_digit($filterAccountRaw))
            ? (int)$filterAccountRaw
            : null;

        if ($filterAccount !== null) {
            $accClause = ' AND account_id = :acc';
        } elseif ($mode !== 'paper') {
            $accClause = ' AND (account_id IS NULL OR account_id IN (SELECT id FROM bybit_accounts WHERE enabled = 1 AND archived_at IS NULL))';
        } else {
            $accClause = '';
        }

        // D0 — стартовый/опорный депозит
        if ($mode === 'paper') {
            $d0 = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
        } elseif ($filterAccount !== null) {
            $eqStmt = $pdo->prepare(
                'SELECT deposit_usdt FROM equity_snapshots
                  WHERE mode = :m AND account_id = :acc
                  ORDER BY period_start ASC LIMIT 1'
            );
            $eqStmt->execute([':m' => $mode, ':acc' => $filterAccount]);
            $eqRow = $eqStmt->fetch(\PDO::FETCH_ASSOC);
            if ($eqRow !== false) {
                $d0 = (float)$eqRow['deposit_usdt'];
            } else {
                try {
                    $d0 = EquityService::computeEquity($mode, $filterAccount)['d0'];
                } catch (\Throwable $e) {
                    $d0 = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
                }
            }
        } else {
            try {
                $d0 = EquityService::computeEquity($mode, null)['d0'];
            } catch (\Throwable $e) {
                $d0 = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
            }
        }

        // Base bind — используется всеми запросами
        $baseBind = [':mode' => $mode];
        if ($filterAccount !== null) {
            $baseBind[':acc'] = $filterAccount;
        }

        // ── Таблицы по периодам (все / s1 / s2) ──
        $weeklyRows  = self::buildPeriodRows($pdo, $mode, $accClause, $baseBind, $filterAccount, $d0, 'week',  26, null);
        $monthlyRows = self::buildPeriodRows($pdo, $mode, $accClause, $baseBind, $filterAccount, $d0, 'month', 24, null);
        $weeklyS1    = self::buildPeriodRows($pdo, $mode, $accClause, $baseBind, $filterAccount, $d0, 'week',  26, 's1');
        $weeklyS2    = self::buildPeriodRows($pdo, $mode, $accClause, $baseBind, $filterAccount, $d0, 'week',  26, 's2');
        $monthlyS1   = self::buildPeriodRows($pdo, $mode, $accClause, $baseBind, $filterAccount, $d0, 'month', 24, 's1');
        $monthlyS2   = self::buildPeriodRows($pdo, $mode, $accClause, $baseBind, $filterAccount, $d0, 'month', 24, 's2');

        // ── Итого (все / s1 / s2) ──
        $totals   = self::fetchTotals($pdo, $mode, $accClause, $baseBind, null);
        $totalsS1 = self::fetchTotals($pdo, $mode, $accClause, $baseBind, 's1');
        $totalsS2 = self::fetchTotals($pdo, $mode, $accClause, $baseBind, 's2');

        // ── Equity-кривые (все / s1 / s2) ──
        $curveAll = self::buildCurve($pdo, $mode, $accClause, $baseBind, $d0, null);
        $curveS1  = self::buildCurve($pdo, $mode, $accClause, $baseBind, $d0, 's1');
        $curveS2  = self::buildCurve($pdo, $mode, $accClause, $baseBind, $d0, 's2');

        // ── SVG-графики ──
        $equitySvgAll = self::buildEquitySvg(
            [['label' => 'Все', 'color' => '#6a9eff', 'points' => $curveAll]],
            $d0
        );
        $equitySvgCompare = self::buildEquitySvg(
            [
                ['label' => 's1 (автомат)', 'color' => '#6aff6a', 'points' => $curveS1],
                ['label' => 's2 (ручные)',  'color' => '#ffaa44', 'points' => $curveS2],
            ],
            $d0
        );

        // ── Список аккаунтов для селектора ──
        $allAccounts = [];
        foreach (BybitAccountsRepo::listEnabled() as $a) {
            $allAccounts[] = ['id' => (int)$a['id'], 'name' => (string)$a['name']];
        }

        return $twig->render($response, 'stats.twig', [
            'mode'              => $currentMode,
            'app_version'       => (string)Config::bootstrap('app.version', '0.7.0'),
            'filter_mode'       => $mode,
            'filter_account'    => $filterAccount,
            'all_accounts'      => $allAccounts,
            'd0'                => $d0,
            // Общие данные
            'weekly'            => $weeklyRows,
            'monthly'           => $monthlyRows,
            'totals'            => $totals,
            'equity_svg_all'    => $equitySvgAll,
            // s1/s2 данные
            'weekly_s1'         => $weeklyS1,
            'weekly_s2'         => $weeklyS2,
            'monthly_s1'        => $monthlyS1,
            'monthly_s2'        => $monthlyS2,
            'totals_s1'         => $totalsS1,
            'totals_s2'         => $totalsS2,
            'equity_svg_compare'=> $equitySvgCompare,
        ]);
    }

    // ── POST endpoints (без изменений) ──────────────────────────────────────

    public function setDeposit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = (array)$request->getParsedBody();
        if (empty($params)) {
            $body = (string)$request->getBody();
            $params = (array)json_decode($body, true);
        }
        try {
            $mode        = trim((string)($params['mode']        ?? ''));
            $periodType  = trim((string)($params['period_type'] ?? ''));
            $periodStart = trim((string)($params['period_start'] ?? ''));
            $depositRaw  = $params['deposit_usdt'] ?? '';
            $accountRaw  = $params['account_id'] ?? null;

            if (!in_array($mode, ['paper', 'testnet', 'live'], true)) {
                throw new \RuntimeException('mode должен быть paper|testnet|live');
            }
            if (!in_array($periodType, ['week', 'month'], true)) {
                throw new \RuntimeException('period_type должен быть week|month');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) {
                throw new \RuntimeException('period_start должен быть YYYY-MM-DD');
            }
            $depositUsdt = (float)str_replace(',', '.', (string)$depositRaw);
            if ($depositUsdt <= 0) {
                throw new \RuntimeException('deposit_usdt должен быть > 0');
            }
            $accountId = ($accountRaw !== null && $accountRaw !== '') ? (int)$accountRaw : null;

            $ok = EquitySnapshotsService::saveSnapshot(
                $mode, $accountId, $periodType, $periodStart, $depositUsdt, 'manual', true
            );
            EventRecorder::event(EventRecorder::INFO, 'equity_snapshot_manual_set', null, [
                'mode'         => $mode,
                'account_id'   => $accountId,
                'period_type'  => $periodType,
                'period_start' => $periodStart,
                'deposit_usdt' => $depositUsdt,
            ]);
            $payload = ['ok' => true, 'saved' => $ok, 'deposit_usdt' => $depositUsdt];
        } catch (\Throwable $e) {
            Logger::get()->error('stats set_deposit failed: ' . $e->getMessage());
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function saveCurrentSnapshots(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $res = EquitySnapshotsService::snapshotNow();
            Logger::get()->info('stats save_current_snapshots ok', ['written' => $res['written'] ?? 0]);
            EventRecorder::event(EventRecorder::INFO, 'equity_snapshots_current_saved', null, [
                'written' => (int)($res['written'] ?? 0),
            ]);
            $payload = ['ok' => true, 'written' => (int)($res['written'] ?? 0)];
        } catch (\Throwable $e) {
            Logger::get()->error('stats save_current_snapshots failed: ' . $e->getMessage());
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function backfillSnapshots(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $res = EquitySnapshotsService::backfillAll(true);
            Logger::get()->info('stats backfill_snapshots ok', ['written' => $res['written'] ?? 0]);
            EventRecorder::event(
                EventRecorder::INFO,
                'equity_snapshots_backfill',
                null,
                ['written' => (int)($res['written'] ?? 0)]
            );
            $payload = [
                'ok'      => true,
                'written' => (int)($res['written'] ?? 0),
                'sample'  => array_slice($res['periods'] ?? [], 0, 10),
            ];
        } catch (\Throwable $e) {
            Logger::get()->error('stats backfill_snapshots failed: ' . $e->getMessage());
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    // ── Приватные helper-методы ─────────────────────────────────────────────

    /**
     * Строит enriched rows для weekly или monthly агрегации.
     *
     * @param string  $granularity 'week' | 'month'
     * @param int     $limit       26 | 24
     * @param ?string $stratId     null | 's1' | 's2'
     */
    private static function buildPeriodRows(
        \PDO $pdo,
        string $mode,
        string $accClause,
        array $baseBind,
        ?int $filterAccount,
        float $d0,
        string $granularity,
        int $limit,
        ?string $stratId
    ): array {
        $stratClause = $stratId !== null ? ' AND strategy_id = :strat' : '';
        $bind        = $baseBind;
        if ($stratId !== null) {
            $bind[':strat'] = $stratId;
        }

        $periodExpr = $granularity === 'week'
            ? "strftime('%Y-W%W', closed_at)"
            : "strftime('%Y-%m', closed_at)";

        $stmt = $pdo->prepare(
            "SELECT {$periodExpr} AS period,
                    COUNT(*) AS trades_count,
                    SUM(CASE WHEN realized_pnl_usdt >= 0 THEN 1 ELSE 0 END) AS wins,
                    SUM(CASE WHEN realized_pnl_usdt <  0 THEN 1 ELSE 0 END) AS losses,
                    SUM(CASE WHEN realized_pnl_usdt >= 0 THEN realized_pnl_usdt ELSE 0 END) AS gross_profit,
                    SUM(CASE WHEN realized_pnl_usdt <  0 THEN realized_pnl_usdt ELSE 0 END) AS gross_loss,
                    SUM(realized_pnl_usdt) AS net_pnl
             FROM trades
             WHERE mode = :mode
               AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
               AND closed_at IS NOT NULL
               {$accClause}{$stratClause}
             GROUP BY period
             ORDER BY period DESC
             LIMIT {$limit}"
        );
        $stmt->execute($bind);
        $rawRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $rangeStmt = $pdo->prepare(
            "SELECT MIN(date(closed_at)) AS d_from, MAX(date(closed_at)) AS d_to
               FROM trades
              WHERE mode = :mode
                AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
                AND closed_at IS NOT NULL
                AND {$periodExpr} = :p
                {$accClause}{$stratClause}"
        );

        foreach ($rawRows as &$row) {
            $rb       = $baseBind;
            $rb[':p'] = (string)$row['period'];
            if ($stratId !== null) {
                $rb[':strat'] = $stratId;
            }
            $rangeStmt->execute($rb);
            $rng = $rangeStmt->fetch(\PDO::FETCH_ASSOC) ?: ['d_from' => null, 'd_to' => null];
            $row['period_from'] = (string)($rng['d_from'] ?? '');
            $row['period_to']   = (string)($rng['d_to']   ?? '');

            if ($granularity === 'week') {
                $key = $row['period_from'] !== ''
                    ? EquitySnapshotsService::mondayOf($row['period_from'])
                    : '';
                $row['period_start_mon'] = $key;
            } else {
                $key = $row['period_from'] !== ''
                    ? EquitySnapshotsService::firstOfMonth($row['period_from'])
                    : '';
                $row['period_start_first'] = $key;
            }
            $row['_period_key'] = $key;
        }
        unset($row);

        $rows       = self::enrichRows($rawRows);
        $periodType = $granularity === 'week' ? 'week' : 'month';

        foreach ($rows as &$row) {
            $ps           = $row['_period_key'] ?? '';
            $depStart     = $ps !== ''
                ? EquitySnapshotsService::getDepositStart($mode, $filterAccount, $periodType, $ps)
                : $d0;
            $depAvailable = is_finite($depStart);
            $row['deposit_start_available'] = $depAvailable;
            $row['deposit_start']           = $depAvailable ? round($depStart, 4) : 0.0;
            $row['pnl_pct'] = ($depAvailable && $depStart > 0)
                ? round(100.0 * (float)$row['net_pnl'] / $depStart, 2)
                : 0.0;
            $row['period_label'] = self::formatRange($row['period_from'], $row['period_to']);
            unset($row['_period_key']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Строит equity-кривую (массив {day, pnl, equity}) для заданной стратегии.
     *
     * @param ?string $stratId null | 's1' | 's2'
     */
    private static function buildCurve(
        \PDO $pdo,
        string $mode,
        string $accClause,
        array $baseBind,
        float $d0,
        ?string $stratId
    ): array {
        $stratClause = $stratId !== null ? ' AND strategy_id = :strat' : '';
        $bind        = $baseBind;
        if ($stratId !== null) {
            $bind[':strat'] = $stratId;
        }

        $stmt = $pdo->prepare(
            "SELECT date(closed_at) AS day, SUM(realized_pnl_usdt) AS day_pnl
               FROM trades
              WHERE mode = :mode
                AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
                AND closed_at IS NOT NULL
                {$accClause}{$stratClause}
              GROUP BY day
              ORDER BY day ASC"
        );
        $stmt->execute($bind);
        $raw = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $curve = [];
        $cum   = 0.0;
        foreach ($raw as $r) {
            $cum     += (float)$r['day_pnl'];
            $curve[] = [
                'day'    => (string)$r['day'],
                'pnl'    => (float)$r['day_pnl'],
                'equity' => $d0 + $cum,
            ];
        }

        if (count($curve) === 0) {
            $curve[] = ['day' => date('Y-m-d'), 'pnl' => 0.0, 'equity' => $d0];
        } else {
            $firstDay  = $curve[0]['day'];
            $beforeDay = (new \DateTime($firstDay))->modify('-1 day')->format('Y-m-d');
            array_unshift($curve, ['day' => $beforeDay, 'pnl' => 0.0, 'equity' => $d0]);
        }

        return $curve;
    }

    /**
     * Возвращает enriched строку итогов (count, wins, losses, gross, net).
     *
     * @param ?string $stratId null | 's1' | 's2'
     */
    private static function fetchTotals(
        \PDO $pdo,
        string $mode,
        string $accClause,
        array $baseBind,
        ?string $stratId
    ): ?array {
        $stratClause = $stratId !== null ? ' AND strategy_id = :strat' : '';
        $bind        = $baseBind;
        if ($stratId !== null) {
            $bind[':strat'] = $stratId;
        }

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS trades_count,
                SUM(CASE WHEN realized_pnl_usdt >= 0 THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN realized_pnl_usdt <  0 THEN 1 ELSE 0 END) AS losses,
                SUM(CASE WHEN realized_pnl_usdt >= 0 THEN realized_pnl_usdt ELSE 0 END) AS gross_profit,
                SUM(CASE WHEN realized_pnl_usdt <  0 THEN realized_pnl_usdt ELSE 0 END) AS gross_loss,
                SUM(realized_pnl_usdt) AS net_pnl
             FROM trades
             WHERE mode = :mode
               AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
               {$accClause}{$stratClause}"
        );
        $stmt->execute($bind);
        $row    = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $result = self::enrichRows([$row]);
        return $result[0] ?? null;
    }

    /**
     * Строит inline SVG с одной или несколькими equity-линиями.
     *
     * @param array $series [['label' => string, 'color' => string, 'points' => [{day,pnl,equity}]], ...]
     */
    private static function buildEquitySvg(array $series, float $d0): string
    {
        // Собираем все уникальные дни для общей оси X
        $allDays = [];
        foreach ($series as $s) {
            foreach ($s['points'] as $p) {
                $allDays[$p['day']] = true;
            }
        }
        ksort($allDays);
        $allDays  = array_keys($allDays);
        $dayIndex = array_flip($allDays);
        $n        = count($allDays);

        if ($n === 0) {
            return '<div style="color:#666;font-size:12px;padding:20px;">Нет данных для графика.</div>';
        }

        $w  = 760;
        $h  = 240;
        $pl = 56;
        $pr = 14;
        $pt = 12;
        $pb = 28;

        $ivW = $w - $pl - $pr;
        $ivH = $h - $pt - $pb;

        // Min/max по всем видимым сериям
        $minV = INF;
        $maxV = -INF;
        foreach ($series as $s) {
            foreach ($s['points'] as $p) {
                $minV = min($minV, (float)$p['equity']);
                $maxV = max($maxV, (float)$p['equity']);
            }
        }
        $minV = min($minV, $d0);
        $maxV = max($maxV, $d0);
        if (abs($maxV - $minV) < 1e-9) {
            $minV -= 1;
            $maxV += 1;
        }

        $xFor = function (int $i) use ($n, $ivW, $pl): float {
            $t = $n > 1 ? $i / ($n - 1) : 0.5;
            return $pl + $t * $ivW;
        };
        $yFor = function (float $v) use ($minV, $maxV, $ivH, $pt): float {
            $t = ($v - $minV) / ($maxV - $minV);
            return $pt + (1 - $t) * $ivH;
        };

        // Y-сетка и подписи
        $yTicks = '';
        for ($k = 0; $k <= 4; $k++) {
            $v = $minV + ($maxV - $minV) * $k / 4;
            $y = $yFor($v);
            $yTicks .= '<text x="' . ($pl - 6) . '" y="' . (round($y, 2) + 3)
                     . '" text-anchor="end" fill="#889" font-size="10">'
                     . number_format($v, 2) . '</text>'
                     . '<line x1="' . $pl . '" y1="' . round($y, 2)
                     . '" x2="' . ($pl + $ivW) . '" y2="' . round($y, 2)
                     . '" stroke="#222" />';
        }

        // Уровень D0
        $yD0   = $yFor($d0);
        $d0Line = '<line x1="' . $pl . '" y1="' . round($yD0, 2)
                . '" x2="' . ($pl + $ivW) . '" y2="' . round($yD0, 2)
                . '" stroke="#444" stroke-dasharray="3,3" />'
                . '<text x="' . ($pl - 6) . '" y="' . (round($yD0, 2) + 3)
                . '" text-anchor="end" fill="#666" font-size="10">D0='
                . number_format($d0, 0) . '</text>';

        // X-подписи: первая, средняя, последняя
        $xLabels = '';
        $idxs = $n === 1 ? [0] : ($n === 2 ? [0, $n - 1] : [0, intval(($n - 1) / 2), $n - 1]);
        foreach ($idxs as $i) {
            $x = $xFor($i);
            $xLabels .= '<text x="' . round($x, 2) . '" y="' . ($pt + $ivH + 16)
                      . '" text-anchor="middle" fill="#889" font-size="10">'
                      . htmlspecialchars($allDays[$i]) . '</text>';
        }

        // Линии по сериям
        $paths = '';
        foreach ($series as $s) {
            $pathD   = '';
            $started = false;
            foreach ($s['points'] as $p) {
                $di = $dayIndex[$p['day']] ?? null;
                if ($di === null) {
                    continue;
                }
                $x = $xFor($di);
                $y = $yFor((float)$p['equity']);
                $pathD .= ($started ? 'L' : 'M') . round($x, 2) . ',' . round($y, 2) . ' ';
                $started = true;
            }
            if ($pathD !== '') {
                $paths .= '<path d="' . $pathD . '" fill="none"'
                        . ' stroke="' . htmlspecialchars($s['color']) . '"'
                        . ' stroke-width="1.6" />';
            }

            // Точки только для первой точки-якоря серии (если серий > 1)
            // пропускаем чтобы не загромождать
        }

        // Точки для однолинейного графика
        if (count($series) === 1) {
            $pts = $series[0]['points'];
            foreach ($pts as $i => $p) {
                $di = $dayIndex[$p['day']] ?? null;
                if ($di === null) {
                    continue;
                }
                $x     = $xFor($di);
                $y     = $yFor((float)$p['equity']);
                $color = $p['equity'] >= $d0 ? '#6aff6a' : '#ff6a6a';
                $paths .= '<circle cx="' . round($x, 2) . '" cy="' . round($y, 2)
                        . '" r="2.5" fill="' . $color . '">'
                        . '<title>' . htmlspecialchars($p['day']) . ' — equity '
                        . number_format((float)$p['equity'], 2, '.', ' ')
                        . ' (PnL дня ' . ($p['pnl'] >= 0 ? '+' : '')
                        . number_format((float)$p['pnl'], 2, '.', ' ') . ')</title></circle>';
            }
        }

        $svg = <<<SVG
<svg viewBox="0 0 {$w} {$h}" width="100%" height="{$h}" xmlns="http://www.w3.org/2000/svg"
     style="background:#161616; border:1px solid #2a2a2a; border-radius:4px 4px 0 0;">
    {$yTicks}
    {$d0Line}
    {$paths}
    {$xLabels}
</svg>
SVG;

        // Легенда под графиком (только для мультисерийных)
        if (count($series) > 1) {
            $legendItems = '';
            foreach ($series as $s) {
                $legendItems .= '<span style="display:inline-flex;align-items:center;gap:5px;">'
                    . '<svg width="18" height="3" xmlns="http://www.w3.org/2000/svg">'
                    . '<rect width="18" height="3" fill="' . htmlspecialchars($s['color']) . '"/></svg>'
                    . '<span>' . htmlspecialchars($s['label']) . '</span>'
                    . '</span>';
            }
            $svg .= '<div style="display:flex;gap:16px;padding:5px 8px;'
                  . 'background:#1a1a1a;border:1px solid #2a2a2a;border-top:none;'
                  . 'border-radius:0 0 4px 4px;font-size:11px;color:#aab;">'
                  . $legendItems . '</div>';
        }

        return $svg;
    }

    private static function formatRange(string $from, string $to): string
    {
        $fmt = static function (string $ymd): string {
            if ($ymd === '' || strlen($ymd) < 10) {
                return '';
            }
            return substr($ymd, 8, 2) . '.' . substr($ymd, 5, 2) . '.' . substr($ymd, 0, 4);
        };
        $a = $fmt($from);
        $b = $fmt($to);
        if ($a === '' && $b === '') {
            return '';
        }
        if ($a === '' || $a === $b) {
            return $b;
        }
        if ($b === '') {
            return $a;
        }
        return $a . '-' . $b;
    }

    private static function enrichRows(array $rows): array
    {
        foreach ($rows as &$r) {
            $cnt    = (int)($r['trades_count'] ?? 0);
            $wins   = (int)($r['wins']   ?? 0);
            $losses = (int)($r['losses'] ?? 0);
            $r['win_rate']     = $cnt > 0 ? round(100.0 * $wins / $cnt, 1) : 0.0;
            $r['trades_count'] = $cnt;
            $r['wins']         = $wins;
            $r['losses']       = $losses;
            $r['gross_profit'] = round((float)($r['gross_profit'] ?? 0), 4);
            $r['gross_loss']   = round((float)($r['gross_loss']   ?? 0), 4);
            $r['net_pnl']      = round((float)($r['net_pnl']      ?? 0), 4);
        }
        unset($r);
        return $rows;
    }
}
