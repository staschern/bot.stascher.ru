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
 * StatsController — вкладка «Результаты» (v0.7.4).
 *
 * GET /stats?mode=paper — таблицы PnL по неделям и месяцам + график equity.
 *
 * Метрики берутся только из закрытых сделок (CLOSED_PROFIT / CLOSED_LOSS)
 * данного режима. Equity начинается от paper_initial_deposit_usdt и
 * наращивается кумулятивной суммой realized_pnl_usdt по closed_at.
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

        // v0.9.0-step7 task6: фильтр по аккаунту.
        // '' или отсутствует — все аккаунты; иначе — конкретный account_id.
        $filterAccountRaw = isset($params['account']) ? (string)$params['account'] : '';
        $filterAccount    = ($filterAccountRaw !== '' && ctype_digit($filterAccountRaw))
            ? (int)$filterAccountRaw
            : null;
        if ($filterAccount !== null) {
            $accClause = ' AND account_id = :acc';
        } elseif ($mode !== 'paper') {
            // В общем режиме скрываем сделки выключенных аккаунтов.
            $accClause = ' AND (account_id IS NULL OR account_id IN (SELECT id FROM bybit_accounts WHERE enabled = 1 AND archived_at IS NULL))';
        } else {
            $accClause = '';
        }

        // D0 — стартовый/опорный депозит для equity-кривой и итогового расчёта %.
        //
        // paper  → paper_initial_deposit_usdt из Settings.
        //
        // live/testnet + конкретный аккаунт:
        //   1) Ранний equity_snapshot для этого аккаунта (заполняется cron_daily / backfill).
        //   2) Текущий баланс с биржи по этому аккаунту (API-вызов).
        //
        // live/testnet + «все» аккаунты:
        //   Сумма текущих балансов всех enabled аккаунтов через EquityService.
        //   deposit_snapshots не содержит account_id → для агрегата там может лежать
        //   старое одно-аккаунтовое значение, которое не отражает реальную общую сумму.
        if ($mode === 'paper') {
            $d0 = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
        } elseif ($filterAccount !== null) {
            // Конкретный аккаунт: пробуем ранний equity_snapshot
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
            // Все аккаунты: суммируем балансы всех enabled аккаунтов через EquityService.
            // Это отражает реальный суммарный капитал под управлением.
            try {
                $d0 = EquityService::computeEquity($mode, null)['d0'];
            } catch (\Throwable $e) {
                $d0 = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
            }
        }

        // ── 1) Агрегация по неделям (ISO-неделя по closed_at в UTC) ──
        // strftime '%Y-W%W' даёт '2026-W19' (W%W — номер недели от 00).
        $weekly = $pdo->prepare(
            "SELECT
                strftime('%Y-W%W', closed_at) AS period,
                COUNT(*)                                                  AS trades_count,
                SUM(CASE WHEN realized_pnl_usdt >= 0 THEN 1 ELSE 0 END)    AS wins,
                SUM(CASE WHEN realized_pnl_usdt <  0 THEN 1 ELSE 0 END)    AS losses,
                SUM(CASE WHEN realized_pnl_usdt >= 0 THEN realized_pnl_usdt ELSE 0 END) AS gross_profit,
                SUM(CASE WHEN realized_pnl_usdt <  0 THEN realized_pnl_usdt ELSE 0 END) AS gross_loss,
                SUM(realized_pnl_usdt)                                     AS net_pnl
             FROM trades
             WHERE mode = :mode
               AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
               AND closed_at IS NOT NULL
               {$accClause}
             GROUP BY period
             ORDER BY period DESC
             LIMIT 26"
        );
        $weeklyBind = [':mode' => $mode];
        if ($filterAccount !== null) { $weeklyBind[':acc'] = $filterAccount; }
        $weekly->execute($weeklyBind);
        $weeklyRowsRaw = $weekly->fetchAll(\PDO::FETCH_ASSOC);
        // v0.9.0-step9 task3: добавляем period_start/period_end из MIN/MAX(closed_at).
        // Делаем отдельным запросом, потому что strftime '%Y-W%W' не всегда
        // соответствует фактическому диапазону (в этой выборке используется
        // дата первой/последней сделки недели — это и есть человеко-читаемый диапазон).
        $weekRangeStmt = $pdo->prepare(
            "SELECT MIN(date(closed_at)) AS d_from, MAX(date(closed_at)) AS d_to
               FROM trades
              WHERE mode = :mode
                AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
                AND closed_at IS NOT NULL
                AND strftime('%Y-W%W', closed_at) = :p
                {$accClause}"
        );
        foreach ($weeklyRowsRaw as &$wr) {
            $b = [':mode' => $mode, ':p' => (string)$wr['period']];
            if ($filterAccount !== null) { $b[':acc'] = $filterAccount; }
            $weekRangeStmt->execute($b);
            $rng = $weekRangeStmt->fetch(\PDO::FETCH_ASSOC) ?: ['d_from' => null, 'd_to' => null];
            $wr['period_from'] = (string)($rng['d_from'] ?? '');
            $wr['period_to']   = (string)($rng['d_to']   ?? '');
            // Каноничный понедельник недели — для lookup snapshot'а.
            $wr['period_start_mon'] = $wr['period_from'] !== ''
                ? EquitySnapshotsService::mondayOf($wr['period_from'])
                : '';
        }
        unset($wr);
        $weeklyRows = self::enrichRows($weeklyRowsRaw);
        // Подкладываем deposit_start и pnl_pct.
        foreach ($weeklyRows as &$wr) {
            $ps = $wr['period_start_mon'] ?? '';
            $depStart = $ps !== ''
                ? EquitySnapshotsService::getDepositStart($mode, $filterAccount, 'week', $ps)
                : $d0;
            $depAvailable = is_finite($depStart);
            $wr['deposit_start_available'] = $depAvailable;
            $wr['deposit_start'] = $depAvailable ? round($depStart, 4) : 0.0;
            $wr['pnl_pct'] = ($depAvailable && $depStart > 0)
                ? round(100.0 * (float)$wr['net_pnl'] / $depStart, 2)
                : 0.0;
            // Форматированный диапазон DD.MM.YYYY-DD.MM.YYYY (если есть).
            $wr['period_label'] = self::formatRange($wr['period_from'], $wr['period_to']);
        }
        unset($wr);

        // ── 2) Агрегация по месяцам ──
        $monthly = $pdo->prepare(
            "SELECT
                strftime('%Y-%m', closed_at) AS period,
                COUNT(*)                                                  AS trades_count,
                SUM(CASE WHEN realized_pnl_usdt >= 0 THEN 1 ELSE 0 END)    AS wins,
                SUM(CASE WHEN realized_pnl_usdt <  0 THEN 1 ELSE 0 END)    AS losses,
                SUM(CASE WHEN realized_pnl_usdt >= 0 THEN realized_pnl_usdt ELSE 0 END) AS gross_profit,
                SUM(CASE WHEN realized_pnl_usdt <  0 THEN realized_pnl_usdt ELSE 0 END) AS gross_loss,
                SUM(realized_pnl_usdt)                                     AS net_pnl
             FROM trades
             WHERE mode = :mode
               AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
               AND closed_at IS NOT NULL
               {$accClause}
             GROUP BY period
             ORDER BY period DESC
             LIMIT 24"
        );
        $monthlyBind = [':mode' => $mode];
        if ($filterAccount !== null) { $monthlyBind[':acc'] = $filterAccount; }
        $monthly->execute($monthlyBind);
        $monthlyRowsRaw = $monthly->fetchAll(\PDO::FETCH_ASSOC);
        $monthRangeStmt = $pdo->prepare(
            "SELECT MIN(date(closed_at)) AS d_from, MAX(date(closed_at)) AS d_to
               FROM trades
              WHERE mode = :mode
                AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
                AND closed_at IS NOT NULL
                AND strftime('%Y-%m', closed_at) = :p
                {$accClause}"
        );
        foreach ($monthlyRowsRaw as &$mr) {
            $b = [':mode' => $mode, ':p' => (string)$mr['period']];
            if ($filterAccount !== null) { $b[':acc'] = $filterAccount; }
            $monthRangeStmt->execute($b);
            $rng = $monthRangeStmt->fetch(\PDO::FETCH_ASSOC) ?: ['d_from' => null, 'd_to' => null];
            $mr['period_from'] = (string)($rng['d_from'] ?? '');
            $mr['period_to']   = (string)($rng['d_to']   ?? '');
            $mr['period_start_first'] = $mr['period_from'] !== ''
                ? EquitySnapshotsService::firstOfMonth($mr['period_from'])
                : '';
        }
        unset($mr);
        $monthlyRows = self::enrichRows($monthlyRowsRaw);
        foreach ($monthlyRows as &$mr) {
            $ps = $mr['period_start_first'] ?? '';
            $depStart = $ps !== ''
                ? EquitySnapshotsService::getDepositStart($mode, $filterAccount, 'month', $ps)
                : $d0;
            $depAvailable = is_finite($depStart);
            $mr['deposit_start_available'] = $depAvailable;
            $mr['deposit_start'] = $depAvailable ? round($depStart, 4) : 0.0;
            $mr['pnl_pct'] = ($depAvailable && $depStart > 0)
                ? round(100.0 * (float)$mr['net_pnl'] / $depStart, 2)
                : 0.0;
            $mr['period_label'] = self::formatRange($mr['period_from'], $mr['period_to']);
        }
        unset($mr);

        // ── 3) Итого за всё время ──
        $totalsStmt = $pdo->prepare(
            "SELECT
                COUNT(*)                                                  AS trades_count,
                SUM(CASE WHEN realized_pnl_usdt >= 0 THEN 1 ELSE 0 END)    AS wins,
                SUM(CASE WHEN realized_pnl_usdt <  0 THEN 1 ELSE 0 END)    AS losses,
                SUM(CASE WHEN realized_pnl_usdt >= 0 THEN realized_pnl_usdt ELSE 0 END) AS gross_profit,
                SUM(CASE WHEN realized_pnl_usdt <  0 THEN realized_pnl_usdt ELSE 0 END) AS gross_loss,
                SUM(realized_pnl_usdt)                                     AS net_pnl
             FROM trades
             WHERE mode = :mode
               AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
               {$accClause}"
        );
        $totalsBind = [':mode' => $mode];
        if ($filterAccount !== null) { $totalsBind[':acc'] = $filterAccount; }
        $totalsStmt->execute($totalsBind);
        $totalsRow = $totalsStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $totals = self::enrichRows([$totalsRow])[0] ?? null;

        // ── 4) Equity-кривая по дням ──
        // Берём все закрытые сделки, отсортированные по closed_at ASC,
        // суммируем кумулятивно, на каждую дату фиксируем equity = D0 + cum_pnl.
        $curveStmt = $pdo->prepare(
            "SELECT
                date(closed_at) AS day,
                SUM(realized_pnl_usdt) AS day_pnl
             FROM trades
             WHERE mode = :mode
               AND status IN ('CLOSED_PROFIT','CLOSED_LOSS')
               AND closed_at IS NOT NULL
               {$accClause}
             GROUP BY day
             ORDER BY day ASC"
        );
        $curveBind = [':mode' => $mode];
        if ($filterAccount !== null) { $curveBind[':acc'] = $filterAccount; }
        $curveStmt->execute($curveBind);
        $curveRaw = $curveStmt->fetchAll(\PDO::FETCH_ASSOC);

        $equityCurve = [];
        $cum = 0.0;
        foreach ($curveRaw as $r) {
            $cum += (float)$r['day_pnl'];
            $equityCurve[] = [
                'day'    => (string)$r['day'],
                'pnl'    => (float)$r['day_pnl'],
                'equity' => $d0 + $cum,
            ];
        }

        // Стартовая точка (для визуального якоря, если данных нет — добавим сегодняшнюю D0).
        if (count($equityCurve) === 0) {
            $equityCurve[] = ['day' => date('Y-m-d'), 'pnl' => 0.0, 'equity' => $d0];
        } else {
            // Добавим виртуальную точку «день до первой сделки» = D0.
            $firstDay  = $equityCurve[0]['day'];
            $beforeDay = (new \DateTime($firstDay))->modify('-1 day')->format('Y-m-d');
            array_unshift($equityCurve, ['day' => $beforeDay, 'pnl' => 0.0, 'equity' => $d0]);
        }

        // ── 5) SVG-данные для графика ──
        $svg = self::buildEquitySvg($equityCurve, $d0);

        // v0.9.0-step7 task6: список аккаунтов для selector
        $allAccounts = [];
        foreach (BybitAccountsRepo::listEnabled() as $a) {
            $allAccounts[] = [
                'id'   => (int)$a['id'],
                'name' => (string)$a['name'],
            ];
        }

        return $twig->render($response, 'stats.twig', [
            'mode'           => $currentMode,
            'app_version'    => (string)Config::bootstrap('app.version', '0.7.0'),
            'filter_mode'    => $mode,
            'filter_account' => $filterAccount,
            'all_accounts'   => $allAccounts,
            'weekly'         => $weeklyRows,
            'monthly'        => $monthlyRows,
            'totals'         => $totals,
            'd0'             => $d0,
            'equity_curve'   => $equityCurve,
            'equity_svg'     => $svg,
        ]);
    }

    /**
     * v0.9.1: POST /stats/set-deposit — ручная установка начального баланса периода.
     *
     * Body (JSON или form): mode, account_id (optional int), period_type (week|month),
     *                       period_start (YYYY-MM-DD), deposit_usdt (float)
     */
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

    /**
     * v0.9.1: POST /stats/save-current-snapshots — принудительное сохранение
     * снапшота ТЕКУЩЕГО периода (неделя + месяц) по всем режимам/аккаунтам.
     * Не дожидаясь cron_daily. Перезаписывает существующее (force=true).
     */
    public function saveCurrentSnapshots(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $res = EquitySnapshotsService::snapshotNow();
            Logger::get()->info('stats save_current_snapshots ok', [
                'written' => $res['written'] ?? 0,
            ]);
            EventRecorder::event(EventRecorder::INFO, 'equity_snapshots_current_saved', null, [
                'written' => (int)($res['written'] ?? 0),
            ]);
            $payload = [
                'ok'      => true,
                'written' => (int)($res['written'] ?? 0),
            ];
        } catch (\Throwable $e) {
            Logger::get()->error('stats save_current_snapshots failed: ' . $e->getMessage());
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * v0.9.0-step9 task4: POST /stats/backfill-snapshots — пересчёт equity_snapshots
     * ретроспективно из истории закрытых trades. Идемпотентно (force=true).
     */
    public function backfillSnapshots(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $res = EquitySnapshotsService::backfillAll(true);
            Logger::get()->info('stats backfill_snapshots ok', [
                'written' => $res['written'] ?? 0,
            ]);
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

    /**
     * Форматирует диапазон "YYYY-MM-DD".. "YYYY-MM-DD" в "DD.MM.YYYY-DD.MM.YYYY".
     * Если границы равны или вторая пустая — возвращает одну дату. Если обе пустые — ''.
     */
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
        if ($a === '' && $b === '') return '';
        if ($a === '' || $a === $b) return $b;
        if ($b === '') return $a;
        return $a . '-' . $b;
    }

    /**
     * Добавляет производные поля к строкам агрегации: win_rate, форматирование.
     */
    private static function enrichRows(array $rows): array
    {
        foreach ($rows as &$r) {
            $cnt    = (int)($r['trades_count'] ?? 0);
            $wins   = (int)($r['wins']   ?? 0);
            $losses = (int)($r['losses'] ?? 0);
            $r['win_rate'] = $cnt > 0 ? round(100.0 * $wins / $cnt, 1) : 0.0;
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

    /**
     * Сборка inline SVG-графика equity по точкам [{day,equity}, ...].
     *
     * Возвращает готовый <svg ...>...</svg> с осями, сеткой, линией и точками.
     */
    private static function buildEquitySvg(array $points, float $d0): string
    {
        $n = count($points);
        if ($n < 1) {
            return '<div style="color:#666;font-size:12px;padding:20px;">Нет данных для графика.</div>';
        }

        $w  = 760;
        $h  = 240;
        $pl = 56; // padding-left под подписи Y
        $pr = 14;
        $pt = 12;
        $pb = 28;

        $ivW = $w - $pl - $pr;
        $ivH = $h - $pt - $pb;

        $values = array_column($points, 'equity');
        $minV   = min($values);
        $maxV   = max($values);
        // Расширим диапазон, чтобы D0 был виден как уровень.
        $minV = min($minV, $d0);
        $maxV = max($maxV, $d0);
        if (abs($maxV - $minV) < 1e-9) {
            $minV -= 1;
            $maxV += 1;
        }

        $xFor = function (int $i) use ($n, $ivW, $pl) {
            $t = $n > 1 ? $i / ($n - 1) : 0.5;
            return $pl + $t * $ivW;
        };
        $yFor = function (float $v) use ($minV, $maxV, $ivH, $pt) {
            $t = ($v - $minV) / ($maxV - $minV);
            return $pt + (1 - $t) * $ivH;
        };

        // Линия equity
        $pathD = '';
        foreach ($points as $i => $p) {
            $x = $xFor($i);
            $y = $yFor((float)$p['equity']);
            $pathD .= ($i === 0 ? 'M' : 'L') . round($x, 2) . ',' . round($y, 2) . ' ';
        }

        // Точки + подписи (только если их меньше 40)
        $dots = '';
        $showLabels = $n <= 40;
        foreach ($points as $i => $p) {
            $x = $xFor($i);
            $y = $yFor((float)$p['equity']);
            $color = $p['equity'] >= $d0 ? '#6aff6a' : '#ff6a6a';
            $dots .= '<circle cx="' . round($x, 2) . '" cy="' . round($y, 2)
                  . '" r="2.5" fill="' . $color . '">'
                  . '<title>' . htmlspecialchars($p['day']) . ' — equity '
                  . number_format((float)$p['equity'], 2, '.', ' ')
                  . ' (PnL дня ' . ($p['pnl'] >= 0 ? '+' : '')
                  . number_format((float)$p['pnl'], 2, '.', ' ') . ')</title></circle>';
        }

        // Уровень D0
        $yD0 = $yFor($d0);
        $d0Line = '<line x1="' . $pl . '" y1="' . round($yD0, 2)
                . '" x2="' . ($pl + $ivW) . '" y2="' . round($yD0, 2)
                . '" stroke="#444" stroke-dasharray="3,3" />'
                . '<text x="' . ($pl - 6) . '" y="' . (round($yD0, 2) + 3)
                . '" text-anchor="end" fill="#666" font-size="10">D0='
                . number_format($d0, 0) . '</text>';

        // Y-оси подписи: 4 деления
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

        // X-оси подписи: первая, средняя, последняя
        $xLabels = '';
        $idxs = $n === 1 ? [0] : ($n === 2 ? [0, $n - 1] : [0, intval(($n - 1) / 2), $n - 1]);
        foreach ($idxs as $i) {
            $x = $xFor($i);
            $xLabels .= '<text x="' . round($x, 2) . '" y="' . ($pt + $ivH + 16)
                      . '" text-anchor="middle" fill="#889" font-size="10">'
                      . htmlspecialchars($points[$i]['day']) . '</text>';
        }

        return <<<SVG
<svg viewBox="0 0 {$w} {$h}" width="100%" height="{$h}" xmlns="http://www.w3.org/2000/svg"
     style="background:#161616; border:1px solid #2a2a2a; border-radius:4px;">
    {$yTicks}
    {$d0Line}
    <path d="{$pathD}" fill="none" stroke="#6a9eff" stroke-width="1.6" />
    {$dots}
    {$xLabels}
</svg>
SVG;
    }
}
