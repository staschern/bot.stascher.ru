<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Trade\EquityService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * TradesController — список сделок и детальная страница.
 *
 * GET /trades?exchange=&status=&symbol=  — список trades с фильтрами
 * GET /trades/{id}                       — детальная страница trade
 *
 * Автообновление через <meta http-equiv="refresh" content="60">.
 *
 * См. spec.md §14 (v0.5.0).
 */
final class TradesController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $twig   = Twig::fromRequest($request);
        $pdo    = Database::pdo();
        $params = $request->getQueryParams();

        // v0.9.0-step7 task2: если параметр exchange не передан вообще — подставляем текущий mode
        // (чтобы увидеть все — явно выбрать «Все» (exchange=)).
        if (array_key_exists('exchange', $params)) {
            $filterExchange = (string)$params['exchange'];
        } else {
            $filterExchange = (string)Config::get('mode', null, 'paper');
        }
        // v0.7.0: дефолтный фильтр статуса — 'active' (OPEN + PENDING_CONDITIONAL).
        // Если параметр status в URL не передан вообще — подставляем 'active'.
        // Чтобы увидеть все, нужно явно выбрать пункт «Все» (status=all) или конкретный статус.
        $filterStatus   = array_key_exists('status', $params) ? (string)$params['status'] : 'active';
        $filterSymbol   = isset($params['symbol'])   ? (string)$params['symbol']   : '';
        // v0.9.0-step6: фильтр по аккаунту. '' — все (сумма по enabled);
        // иначе цифра — конкретный account_id.
        $filterAccountRaw = isset($params['account']) ? (string)$params['account'] : '';
        $filterAccount    = ($filterAccountRaw !== '' && ctype_digit($filterAccountRaw))
            ? (int)$filterAccountRaw
            : null;

        // v0.9.0-step9 task2: фильтр по периоду.
        //   period=0|1 (включён ли), по умолчанию 0 — выключен.
        //   df=placed|opened|closed|auto; auto → closed для статусов closed/cancelled/closed_or_cancelled, иначе placed.
        //   from/to — YYYY-MM-DD. При пустых и period=1 — подставляем last 7 days.
        $filterPeriodEnabled = isset($params['period']) ? ($params['period'] === '1' || $params['period'] === 1 || $params['period'] === 'on') : false;
        $filterDateField     = isset($params['df']) ? (string)$params['df'] : 'auto';
        $allowedDf = ['auto','placed','opened','closed'];
        if (!in_array($filterDateField, $allowedDf, true)) {
            $filterDateField = 'auto';
        }
        $filterFrom = isset($params['from']) ? trim((string)$params['from']) : '';
        $filterTo   = isset($params['to'])   ? trim((string)$params['to'])   : '';
        // Проверяем формат YYYY-MM-DD.
        if ($filterFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) { $filterFrom = ''; }
        if ($filterTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   { $filterTo   = ''; }
        if ($filterPeriodEnabled && $filterFrom === '' && $filterTo === '') {
            $filterTo   = gmdate('Y-m-d');
            $filterFrom = gmdate('Y-m-d', strtotime('-7 days'));
        }
        // Авто-выбор поля даты по статусу.
        $resolvedDateField = $filterDateField;
        if ($resolvedDateField === 'auto') {
            $closedLike = ['closed','cancelled','closed_or_cancelled'];
            $resolvedDateField = in_array($filterStatus, $closedLike, true) ? 'closed' : 'placed';
        }
        $dfCol = [
            'placed' => 'o.placed_at',
            'opened' => 't.opened_at',
            'closed' => 't.closed_at',
        ][$resolvedDateField];

        // v0.8.0.9: пагинация и сортировка.
        $perPage = 50;
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $sortKey = isset($params['sort']) ? (string)$params['sort'] : 'created';
        $sortDir = isset($params['dir'])  ? (strtolower((string)$params['dir']) === 'asc' ? 'ASC' : 'DESC') : 'DESC';
        // Белый список полей для сортировки (защита от SQL-инъекции).
        $sortMap = [
            'created' => 't.created_at',
            'placed'  => 'o.placed_at',
            'opened'  => 't.opened_at',
            'closed'  => 't.closed_at',
        ];
        if (!isset($sortMap[$sortKey])) {
            $sortKey = 'created';
        }
        $sortCol = $sortMap[$sortKey];

        // Построить запрос
        $where  = [];
        $bind   = [];

        if ($filterExchange !== '') {
            $where[] = "t.mode = :exchange";
            $bind[':exchange'] = $filterExchange;
        }

        if ($filterStatus === 'active') {
            // v0.7.3: «активные» = условные + открытые + усреднённые позиции.
            $where[] = "t.status IN ('OPEN','PENDING_CONDITIONAL','AVERAGED')";
        } elseif ($filterStatus === 'open') {
            $where[] = "t.status = 'OPEN'";
        } elseif ($filterStatus === 'averaged') {
            // v0.7.3: отдельный фильтр «усреднённые».
            $where[] = "t.status = 'AVERAGED'";
        } elseif ($filterStatus === 'closed') {
            $where[] = "t.status IN ('CLOSED_PROFIT','CLOSED_LOSS')";
        } elseif ($filterStatus === 'cancelled') {
            $where[] = "t.status = 'CANCELLED'";
        } elseif ($filterStatus === 'closed_or_cancelled') {
            // v0.9.0-step9 task2: объединённый фильтр closed+cancelled.
            $where[] = "t.status IN ('CLOSED_PROFIT','CLOSED_LOSS','CANCELLED')";
        } elseif ($filterStatus === 'pending') {
            $where[] = "t.status = 'PENDING_CONDITIONAL'";
        }
        // 'all' или '' (после явного сброса) — без фильтра по статусу

        if ($filterSymbol !== '') {
            $where[] = "t.symbol LIKE :symbol";
            $bind[':symbol'] = '%' . $filterSymbol . '%';
        }

        // v0.9.0-step6: фильтр по аккаунту (только если выбран конкретный id).
        if ($filterAccount !== null) {
            $where[] = "t.account_id = :account_id";
            $bind[':account_id'] = $filterAccount;
        }

        // v0.9.0-step9 task2: фильтр по периоду.
        if ($filterPeriodEnabled && ($filterFrom !== '' || $filterTo !== '')) {
            if ($filterFrom !== '') {
                $where[] = "{$dfCol} >= :date_from";
                $bind[':date_from'] = $filterFrom . 'T00:00:00';
            }
            if ($filterTo !== '') {
                $where[] = "{$dfCol} <= :date_to";
                $bind[':date_to'] = $filterTo . 'T23:59:59';
            }
        }

        $whereClause = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

        // v0.9.0-step9: для COUNT нужен тот же JOIN если в WHERE используется o.placed_at.
        $usesEntryJoin = (strpos($whereClause, 'o.placed_at') !== false);
        $countJoin = $usesEntryJoin
            ? "LEFT JOIN orders o ON o.id = (SELECT id FROM orders WHERE trade_id = t.id AND purpose = 'entry_conditional' ORDER BY placed_at ASC LIMIT 1)"
            : '';
        $countSql = "SELECT COUNT(*) FROM trades t {$countJoin} {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bind);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // Подзапрос entry_order — первый entry_conditional по каждому trade.
        // Из него берём placed_at и (для pending) qty/sl_price/last_seen_price.
        // §6: отображаем АКТУАЛЬНЫЕ SL и trailing-trigger (вместо tp_init после размена TP на TS).
        // Для PENDING_CONDITIONAL — sl_init/tp_init (первоначальные), для OPEN/AVERAGED — sl_current/trailing_trigger.
        $sql = "SELECT
                    t.id, t.mode, t.strategy_id, t.symbol, t.side, t.status,
                    t.leverage, t.entry_ref, t.entry_real, t.realized_pnl_usdt,
                    t.created_at, t.opened_at, t.closed_at,
                    t.tp_init, t.sl_init, t.sl_current,
                    t.trailing_pct, t.trailing_trigger,
                    t.trailing_activated_at,
                    t.avg_price, t.qty_avg, t.break_even_price,
                    t.color_label,
                    t.ignore_sl_until_open,
                    t.recovered_from_trade_id,
                    t.recovered_at,
                    t.account_id, t.account_name,
                    p.avg_entry_price, p.qty, p.qty_initial AS pos_qty_initial,
                    p.sl_price, p.tp_price,
                    p.trailing_pct AS pos_trailing_pct,
                    p.trailing_trigger_price AS pos_trailing_trigger,
                    p.last_price AS pos_last_price,
                    p.exchange as pos_exchange, p.closed_at as pos_closed_at,
                    p.realised_pnl_usdt as pos_pnl,
                    o.placed_at        AS order_placed_at,
                    o.qty              AS order_qty,
                    o.sl_price         AS order_sl,
                    o.trigger_price    AS order_trigger,
                    o.last_seen_price  AS order_last_price,
                    o.last_seen_at     AS order_last_at,
                    avg_o.status       AS avg_order_status,
                    avg_o.trigger_price AS avg_order_trigger,
                    avg_o.qty          AS avg_order_qty
                FROM trades t
                LEFT JOIN positions p ON p.trade_id = t.id
                LEFT JOIN orders o ON o.id = (
                    SELECT id FROM orders
                    WHERE trade_id = t.id AND purpose = 'entry_conditional'
                    ORDER BY placed_at ASC LIMIT 1
                )
                LEFT JOIN orders avg_o ON avg_o.id = (
                    SELECT id FROM orders
                    WHERE trade_id = t.id AND purpose = 'avg'
                    ORDER BY placed_at DESC LIMIT 1
                )
                {$whereClause}
                ORDER BY {$sortCol} {$sortDir}, t.id DESC
                LIMIT :_limit OFFSET :_offset";

        $stmt = $pdo->prepare($sql);
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':_limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':_offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $trades = $stmt->fetchAll();

        // v0.7.0 #4: для открытых позиций считаем PnL (USDT + %) + state прогресс-бара.
        $takerFeePct = (float)Config::get('taker_fee_pct', null, 0.055);

        // Для pending conditional — досчитаем «% пути SL→Entry» в PHP (чтобы в Twig было чисто).
        // Для OPEN — считаем плавающий PnL и state прогресс-бара (BE / trail / trail-active).
        foreach ($trades as &$tr) {
            $tr['pending_progress_pct'] = null; // 0..100, null если неприменимо
            $tr['open_pnl_usdt']    = null;
            $tr['open_pnl_pct']     = null;
            $tr['open_progress_pct'] = null;
            $tr['open_progress_state'] = null; // 'before_be' | 'to_trigger' | 'trailing_active'
            $tr['open_be_price']    = null;
            $tr['open_current_price'] = null;

            // v0.7.0 §6: выбираем что показывать в колонках SL и TP
            //   PENDING_CONDITIONAL: sl_init / tp_init (первоначальные)
            //   OPEN / AVERAGED   : sl_current (§6.2 или §7.3) / trailing_trigger (§6.3 или §7.2)
            if ($tr['status'] === 'PENDING_CONDITIONAL') {
                $tr['display_sl'] = $tr['sl_init'];
                $tr['display_tp'] = $tr['tp_init'];
            } else {
                $tr['display_sl'] = $tr['sl_current'] ?? $tr['sl_init'];
                $tr['display_tp'] = $tr['trailing_trigger'] ?? $tr['tp_init'];
            }

            // v0.8.0.9: выбираем qty_show и entry_show для расчёта USDT-объёма и potential PnL TP/SL
            $qtyShow = null;
            $entryShow = null;
            if ($tr['status'] === 'PENDING_CONDITIONAL') {
                $qtyShow = ($tr['order_qty'] !== null) ? (float)$tr['order_qty'] : null;
                $entryShow = ($tr['entry_ref'] !== null) ? (float)$tr['entry_ref']
                              : (($tr['order_trigger'] !== null) ? (float)$tr['order_trigger'] : null);
            } else {
                // OPEN / AVERAGED / CLOSED / CANCELLED: берём фактическую позицию
                $qtyShow = ($tr['qty'] !== null) ? (float)$tr['qty']
                            : (($tr['pos_qty_initial'] !== null) ? (float)$tr['pos_qty_initial'] : null);
                $entryShow = ($tr['avg_entry_price'] !== null) ? (float)$tr['avg_entry_price']
                              : (($tr['entry_real'] !== null) ? (float)$tr['entry_real']
                              : (($tr['entry_ref'] !== null) ? (float)$tr['entry_ref'] : null));
            }
            $tr['qty_show'] = $qtyShow;
            $tr['qty_usdt'] = ($qtyShow !== null && $entryShow !== null) ? ($qtyShow * $entryShow) : null;

            // potential PnL при достижении уровня TP или SL (USDT).
            $tr['tp_pnl_usdt'] = null; // прибыль (зелёным)
            $tr['sl_pnl_usdt'] = null; // убыток (красным)
            if ($qtyShow !== null && $entryShow !== null && $qtyShow > 0 && $entryShow > 0) {
                $sign = ($tr['side'] === 'long') ? 1.0 : -1.0;
                if ($tr['display_tp'] !== null) {
                    $tpLvl = (float)$tr['display_tp'];
                    $tr['tp_pnl_usdt'] = ($tpLvl - $entryShow) * $qtyShow * $sign;
                }
                if ($tr['display_sl'] !== null) {
                    $slLvl = (float)$tr['display_sl'];
                    $tr['sl_pnl_usdt'] = ($slLvl - $entryShow) * $qtyShow * $sign;
                }
            }

            // v0.8.0.10/.11: trailing tracking — если trailing активирован,
            // подменяем TP-слот на текущий уровень trailing stop (= pos.sl_price)
            // и показываем прибыль на этом уровне.
            $tr['trailing_active'] = !empty($tr['trailing_activated_at']);
            $tr['trailing_stop_price'] = null;
            $tr['trailing_stop_pnl_usdt'] = null;
            if ($tr['trailing_active'] && !empty($tr['sl_price'])) {
                $tsl = (float)$tr['sl_price'];
                $tr['trailing_stop_price'] = $tsl;
                if ($qtyShow !== null && $entryShow !== null && $qtyShow > 0 && $entryShow > 0) {
                    $sign = ($tr['side'] === 'long') ? 1.0 : -1.0;
                    $tr['trailing_stop_pnl_usdt'] = ($tsl - $entryShow) * $qtyShow * $sign;
                }
            }

            // v0.8.0.11: если trailing активирован — первая строка SL должна
            // показывать ИСХОДНЫЙ SL стратегии (sl_init), а не текущий sl_current,
            // чтобы не дублировать синюю строку trailing-стопа. PnL пересчитываем
            // на sl_init (это всегда убыток).
            if ($tr['trailing_active'] && $tr['sl_init'] !== null) {
                $tr['display_sl'] = $tr['sl_init'];
                if ($qtyShow !== null && $entryShow !== null && $qtyShow > 0 && $entryShow > 0) {
                    $sign = ($tr['side'] === 'long') ? 1.0 : -1.0;
                    $tr['sl_pnl_usdt'] = ((float)$tr['sl_init'] - $entryShow) * $qtyShow * $sign;
                }
            }

            // v0.7.0 §6.4: состояние ордера усреднения для колонки Avg
            //   none    — «—» (не выставлен)
            //   placed  — серым (выставлен, ждём срабатывания)
            //   filled  — красным (сработал)
            //   cancelled — «—» (сделка закрылась до усреднения)
            $avgStatus = $tr['avg_order_status'] ?? null;
            $tr['display_avg_price'] = null;
            $tr['display_avg_state'] = 'none';
            if ($avgStatus === 'placed') {
                $tr['display_avg_price'] = $tr['avg_order_trigger'] !== null
                    ? (float)$tr['avg_order_trigger']
                    : ($tr['avg_price'] !== null ? (float)$tr['avg_price'] : null);
                $tr['display_avg_state'] = 'placed';
            } elseif ($avgStatus === 'filled') {
                $tr['display_avg_price'] = $tr['avg_order_trigger'] !== null
                    ? (float)$tr['avg_order_trigger']
                    : ($tr['avg_price'] !== null ? (float)$tr['avg_price'] : null);
                $tr['display_avg_state'] = 'filled';
            }

            if ($tr['status'] === 'PENDING_CONDITIONAL'
                && $tr['order_last_price'] !== null
                && $tr['order_trigger']    !== null
                && $tr['sl_init']          !== null
            ) {
                $price   = (float)$tr['order_last_price'];
                $trigger = (float)$tr['order_trigger'];
                $sl      = (float)$tr['sl_init'];
                $denom   = $sl - $trigger;
                if (abs($denom) > 1e-12) {
                    if ($tr['side'] === 'short') {
                        // SL выше trigger; цена идёт вниз; 0% на SL, 100% на trigger
                        $progress = ($sl - $price) / ($sl - $trigger) * 100.0;
                    } else {
                        // long: SL ниже trigger; цена идёт вверх; 0% на SL, 100% на trigger
                        $progress = ($price - $sl) / ($trigger - $sl) * 100.0;
                    }
                    $tr['pending_progress_pct'] = max(-999.0, min(999.0, $progress));
                }
            }

            // v0.7.0 #4 / v0.7.3: открытые и усреднённые позиции — PnL + прогресс-бар.
            //   OPEN     : PnL/BE считаем от entry_real × pos_qty_initial.
            //   AVERAGED : PnL/BE считаем от ТЕКУЩЕЙ позиции (p.qty × p.avg_entry_price),
            //              т.к. после §7 в positions лежит уже объединённый лот.
            $isOpenLike = ($tr['status'] === 'OPEN' || $tr['status'] === 'AVERAGED');
            if ($isOpenLike
                && $tr['pos_last_price'] !== null
                && (float)$tr['leverage'] > 0
            ) {
                $cur     = (float)$tr['pos_last_price'];
                $lev     = (float)$tr['leverage'];
                $sign    = ($tr['side'] === 'long') ? 1.0 : -1.0;

                if ($tr['status'] === 'AVERAGED'
                    && $tr['qty'] !== null
                    && $tr['avg_entry_price'] !== null
                ) {
                    // После усреднения — используем объединённую позицию.
                    $entry = (float)$tr['avg_entry_price'];
                    $qty1  = (float)$tr['qty'];
                } elseif ($tr['entry_real'] !== null && $tr['pos_qty_initial'] !== null) {
                    $entry = (float)$tr['entry_real'];
                    $qty1  = (float)$tr['pos_qty_initial'];
                } else {
                    // нет данных для расчёта — пропускаем
                    continue;
                }

                $pnlUsdt = ($cur - $entry) * $qty1 * $sign;
                $marginInit = ($entry * $qty1) / $lev;
                $pnlPct  = $marginInit > 0 ? ($pnlUsdt / $marginInit * 100.0) : 0.0;
                $tr['open_pnl_usdt']    = $pnlUsdt;
                $tr['open_pnl_pct']     = $pnlPct;
                $tr['open_current_price'] = $cur;

                // BE:
                //   AVERAGED — break_even_price (рассчитан в §7.1, с учётом комиссий обоих лотов).
                //   OPEN     — break_even_price если есть, иначе entry_real ± 2 × taker_fee.
                if (!empty($tr['break_even_price'])) {
                    $be = (float)$tr['break_even_price'];
                } else {
                    $be = $entry * (1 + $sign * 2.0 * $takerFeePct / 100.0);
                }
                $tr['open_be_price'] = $be;

                // Прогресс-бар (3 состояния).
                // Ориентация: для long «lucky» = вверх; для short = вниз.
                $slCur  = $tr['sl_current'] !== null ? (float)$tr['sl_current']
                          : (isset($tr['sl_price']) && $tr['sl_price'] !== null ? (float)$tr['sl_price'] : null);
                $trigTr = $tr['pos_trailing_trigger'] !== null ? (float)$tr['pos_trailing_trigger']
                          : ($tr['trailing_trigger'] !== null ? (float)$tr['trailing_trigger'] : null);
                $trPct  = $tr['pos_trailing_pct'] !== null ? (float)$tr['pos_trailing_pct']
                          : ($tr['trailing_pct'] !== null ? (float)$tr['trailing_pct'] : null);

                $isInProfitDirection = ($sign > 0) ? ($cur >= $be) : ($cur <= $be);

                if (!$isInProfitDirection && $slCur !== null) {
                    // Красный бар: BE → SL, 0% у BE, 100% у SL.
                    // Чем длиннее красный — тем ближе к SL (хуже).
                    $denom = abs($slCur - $be);
                    if ($denom > 1e-12) {
                        $progressRaw = ($sign > 0)
                            ? ($be - $cur) / ($be - $slCur) * 100.0
                            : ($cur - $be) / ($slCur - $be) * 100.0;
                        $tr['open_progress_pct']   = max(-999.0, min(999.0, $progressRaw));
                        $tr['open_progress_state'] = 'before_be';
                    }
                } elseif ($trigTr !== null) {
                    // Зелёный бар (или ярко-зелёный если трейлинг уже сработал): BE → trigger_trailing
                    $denom = abs($trigTr - $be);
                    if ($denom > 1e-12) {
                        $progressRaw = ($sign > 0)
                            ? ($cur - $be) / ($trigTr - $be) * 100.0
                            : ($be - $cur) / ($be - $trigTr) * 100.0;
                        $tr['open_progress_pct'] = max(-999.0, min(999.0, $progressRaw));
                        // Трейлинг активен, если цена уже достигла trigger.
                        $trailingActive = ($sign > 0) ? ($cur >= $trigTr) : ($cur <= $trigTr);
                        $tr['open_progress_state'] = $trailingActive ? 'trailing_active' : 'to_trigger';
                    }
                }
            }
        }
        unset($tr);

        // Список ранее использованных тикеров (для autocomplete на форме s2)
        $knownSymbols = $pdo
            ->query('SELECT DISTINCT symbol FROM trades WHERE symbol IS NOT NULL ORDER BY symbol ASC')
            ->fetchAll(\PDO::FETCH_COLUMN);

        // v0.7.0 #7: эквити виджет сверху (для текущего режима)
        // v0.9.0-step6: если выбран конкретный аккаунт в фильтрах — заголовок
        // и счётчики показывают данные только этого аккаунта.
        $currentMode = (string)Config::get('mode', null, 'paper');
        $equity = EquityService::computeEquity($currentMode, $filterAccount);

        // v0.7.1 #1 / v0.7.3: счётчики позиций в текущем режиме (OPEN / AVERAGED / PENDING).
        // v0.9.0-step6: фильтр по account_id для testnet/live.
        $countsSql = "SELECT status, side, COUNT(*) AS cnt
             FROM trades
             WHERE mode = :mode AND status IN ('OPEN','PENDING_CONDITIONAL','AVERAGED')";
        $countsBind = [':mode' => $currentMode];
        if ($filterAccount !== null && ($currentMode === 'testnet' || $currentMode === 'live')) {
            $countsSql .= " AND account_id = :acc";
            $countsBind[':acc'] = $filterAccount;
        }
        $countsSql .= " GROUP BY status, side";
        $countsStmt = $pdo->prepare($countsSql);
        $countsStmt->execute($countsBind);
        $counts = [
            'open'     => ['long' => 0, 'short' => 0, 'total' => 0],
            'averaged' => ['long' => 0, 'short' => 0, 'total' => 0],
            'pending'  => ['long' => 0, 'short' => 0, 'total' => 0],
        ];
        foreach ($countsStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['status'] === 'OPEN')                 { $bucket = 'open'; }
            elseif ($row['status'] === 'AVERAGED')         { $bucket = 'averaged'; }
            else                                            { $bucket = 'pending'; }
            $side = ($row['side'] === 'long') ? 'long' : 'short';
            $counts[$bucket][$side]   += (int)$row['cnt'];
            $counts[$bucket]['total'] += (int)$row['cnt'];
        }

        // v0.9.0-step4c: список enabled аккаунтов текущего network для мультиселекта в форме s2.
        // v0.9.0-step7 task5: фильтруем только те аккаунты, у которых s2_enabled=1.
        $manualAccounts = [];
        if ($currentMode === 'testnet' || $currentMode === 'live') {
            foreach (BybitAccountsRepo::getEnabledForNetworkAndStrategy($currentMode, 's2') as $a) {
                $manualAccounts[] = [
                    'id'   => (int)$a['id'],
                    'name' => (string)$a['name'],
                ];
            }
        }

        // v0.9.0-step6: список всех аккаунтов (вкл./выкл., не archived)
        // для select-фильтра в filter-bar. Показываем все для testnet и все для live,
        // чтобы можно было отфильтровать исторические сделки по выключенным аккаунтам.
        $filterAccounts = [];
        $filterAccountName = '';
        foreach (BybitAccountsRepo::listAll() as $a) {
            $filterAccounts[] = [
                'id'      => (int)$a['id'],
                'name'    => (string)$a['name'],
                'network' => (string)$a['network'],
                'enabled' => (int)$a['enabled'] === 1,
            ];
            if ($filterAccount !== null && (int)$a['id'] === $filterAccount) {
                $filterAccountName = (string)$a['name'];
            }
        }

        return $twig->render($response, 'trades.twig', [
            'mode'          => $currentMode,
            'app_version'   => (string)Config::bootstrap('app.version', '0.7.0'),
            'trades'        => $trades,
            'filter_exchange' => $filterExchange,
            'filter_status'   => $filterStatus,
            'filter_symbol'   => $filterSymbol,
            // v0.9.0-step9 task2: период.
            'filter_period_enabled' => $filterPeriodEnabled,
            'filter_date_field'     => $filterDateField,    // 'auto' или явный выбор
            'filter_date_field_resolved' => $resolvedDateField, // что реально используется
            'filter_from' => $filterFrom,
            'filter_to'   => $filterTo,
            'total'           => $totalRows,
            'shown'           => count($trades),
            'known_symbols'   => $knownSymbols,
            'equity'          => $equity,
            'counts'          => $counts,
            // v0.8.0.9: пагинация + сортировка
            'page'            => $page,
            'per_page'        => $perPage,
            'total_pages'     => $totalPages,
            'sort_key'        => $sortKey,
            'sort_dir'        => strtolower($sortDir),
            // v0.9.0-step4c: enabled аккаунты для мультиселекта s2
            'manual_accounts' => $manualAccounts,
            // v0.9.0-step6: filter-bar account dropdown
            'filter_account'      => $filterAccount,    // int|null
            'filter_account_name' => $filterAccountName, // имя выбранного (или '')
            'filter_accounts'     => $filterAccounts,    // весь список для select
        ]);
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $twig    = Twig::fromRequest($request);
        $pdo     = Database::pdo();
        $tradeId = (int)($args['id'] ?? 0);

        if ($tradeId === 0) {
            $response->getBody()->write('<p>Trade не найден</p>');
            return $response->withStatus(404);
        }

        // Основная инфо о сделке
        $stmt = $pdo->prepare('SELECT * FROM trades WHERE id = :id');
        $stmt->execute([':id' => $tradeId]);
        $trade = $stmt->fetch();

        if ($trade === false) {
            $response->getBody()->write('<p>Trade не найден</p>');
            return $response->withStatus(404);
        }

        // Ордера
        $stmtOrders = $pdo->prepare('SELECT * FROM orders WHERE trade_id = :id ORDER BY placed_at DESC');
        $stmtOrders->execute([':id' => $tradeId]);
        $orders = $stmtOrders->fetchAll();

        // Позиции
        $stmtPos = $pdo->prepare('SELECT * FROM positions WHERE trade_id = :id ORDER BY opened_at DESC');
        $stmtPos->execute([':id' => $tradeId]);
        $positions = $stmtPos->fetchAll();

        // События
        $stmtEvents = $pdo->prepare('SELECT * FROM trade_events WHERE trade_id = :id ORDER BY ts DESC LIMIT 100');
        $stmtEvents->execute([':id' => $tradeId]);
        $events = $stmtEvents->fetchAll();

        return $twig->render($response, 'trade_detail.twig', [
            'mode'        => (string)Config::get('mode', null, 'paper'),
            'app_version' => (string)Config::bootstrap('app.version', '0.7.0'),
            'trade'       => $trade,
            'orders'      => $orders,
            'positions'   => $positions,
            'events'      => $events,
        ]);
    }

    /**
     * v0.7.1 #2: POST /trades/{id}/label — смена цветовой метки.
     * Body: form-urlencoded или JSON { label: '' | yellow | green | red | blue | purple }
     */
    public function setLabel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tradeId = (int)($args['id'] ?? 0);
        $allowed = ['', 'yellow', 'green', 'red', 'blue', 'purple'];

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $raw  = (string)$request->getBody();
            $body = json_decode($raw, true) ?: [];
        }
        $label = isset($body['label']) ? (string)$body['label'] : '';
        if (!in_array($label, $allowed, true)) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'invalid_label']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        $pdo = Database::pdo();
        $upd = $pdo->prepare('UPDATE trades SET color_label = :lbl WHERE id = :id');
        $upd->execute([':lbl' => $label, ':id' => $tradeId]);

        $response->getBody()->write(json_encode([
            'ok'       => true,
            'trade_id' => $tradeId,
            'label'    => $label,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * v0.8.0.12: POST /trades/{id}/restore — выставить вновь conditional на основе
     * отменённого (status=CANCELLED). Создаётся НОВЫЙ trade со ссылкой
     * recovered_from_trade_id на старый, копируются параметры входа, и ставится
     * новый conditional через адаптер. Флаг ignore_sl_until_open=1 запрещает
     * pre-fill cancel по пересечению SL до фактического перехода в OPEN.
     */
    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tradeId = (int)($args['id'] ?? 0);
        if ($tradeId === 0) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'bad_id']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $pdo = Database::pdo();

        // 1. Найти исходный trade и убедиться, что он CANCELLED + есть параметры входа.
        // v0.9.0-step4d: выбираем также account_id+account_name для multi-account.
        $stmt = $pdo->prepare(
            'SELECT id, mode, strategy_id, symbol, side, status, entry_ref,
                    tp_init, sl_init, leverage, margin_mode, color_label,
                    p_for_strategy_calc, signal_target_pct, signal_w7, signal_rsi,
                    signal_id, account_id, account_name
             FROM trades WHERE id = :id'
        );
        $stmt->execute([':id' => $tradeId]);
        $old = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($old === false) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'not_found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
        if ((string)$old['status'] !== 'CANCELLED') {
            $response->getBody()->write(json_encode([
                'ok' => false, 'error' => 'wrong_status', 'status' => $old['status'],
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }
        if ($old['entry_ref'] === null || $old['sl_init'] === null || $old['tp_init'] === null) {
            $response->getBody()->write(json_encode([
                'ok' => false, 'error' => 'missing_entry_params',
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }

        $mode = (string)$old['mode'];
        if (!in_array($mode, ['paper', 'testnet', 'live'], true)) {
            $response->getBody()->write(json_encode([
                'ok' => false, 'error' => 'mode_not_supported', 'mode' => $mode,
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // 2. Найти исходный entry_conditional, чтобы скопировать qty.
        $stmtQty = $pdo->prepare(
            "SELECT qty FROM orders
             WHERE trade_id = :id AND purpose = 'entry_conditional'
             ORDER BY placed_at ASC LIMIT 1"
        );
        $stmtQty->execute([':id' => $tradeId]);
        $oldQty = $stmtQty->fetchColumn();
        if ($oldQty === false || (float)$oldQty <= 0) {
            $response->getBody()->write(json_encode([
                'ok' => false, 'error' => 'no_qty',
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }
        $qty = (float)$oldQty;

        // 3. INSERT нового trade (статус PENDING_CONDITIONAL) с флагом восстановления.
        $now = gmdate('Y-m-d\\TH:i:s.v\\Z');
        $sideLower = (string)$old['side']; // 'long'|'short'

        // v0.9.0-step4d: восстанавливаем в тот же account_id/account_name, что был в оригинале.
        $accIdOld   = (isset($old['account_id']) && $old['account_id'] !== null) ? (int)$old['account_id'] : null;
        $accNameOld = isset($old['account_name']) ? (string)$old['account_name'] : null;

        $insTrade = $pdo->prepare(
            'INSERT INTO trades
             (mode, strategy_id, symbol, side, signal_target_pct, signal_w7, signal_rsi,
              signal_id, status, entry_ref, leverage, margin_mode,
              tp_init, sl_init, sl_current, p_for_strategy_calc, color_label,
              ignore_sl_until_open, recovered_from_trade_id, recovered_at, created_at,
              account_id, account_name)
             VALUES
             (:mode, :sid, :sym, :side, :tp, :w7, :rsi,
              :signalid, :status, :entry, :lev, :mm,
              :tpinit, :slinit, :slcur, :p, :clr,
              1, :recfrom, :now, :now,
              :accid, :accname)'
        );
        $insTrade->execute([
            ':mode'    => $mode,
            ':sid'     => (string)($old['strategy_id'] ?? 's1'),
            ':sym'     => (string)$old['symbol'],
            ':side'    => $sideLower,
            ':tp'      => $old['signal_target_pct'] !== null ? (float)$old['signal_target_pct'] : null,
            ':w7'      => $old['signal_w7'] !== null ? (int)$old['signal_w7'] : null,
            ':rsi'     => $old['signal_rsi'] !== null ? (float)$old['signal_rsi'] : null,
            ':signalid'=> $old['signal_id'] !== null ? (int)$old['signal_id'] : null,
            ':status'  => 'PENDING_CONDITIONAL',
            ':entry'   => (float)$old['entry_ref'],
            ':lev'     => $old['leverage'] !== null ? (int)$old['leverage'] : null,
            ':mm'      => (string)($old['margin_mode'] ?? 'cross'),
            ':tpinit'  => (float)$old['tp_init'],
            ':slinit'  => (float)$old['sl_init'],
            ':slcur'   => (float)$old['sl_init'],
            ':p'       => $old['p_for_strategy_calc'] !== null ? (float)$old['p_for_strategy_calc'] : null,
            ':clr'     => (string)($old['color_label'] ?? ''),
            ':recfrom' => $tradeId,
            ':now'     => $now,
            ':accid'   => $accIdOld,
            ':accname' => $accNameOld,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // 4. Сформировать новый order_link_id и параметры conditional.
        $bybitSide = ($sideLower === 'long') ? 'Buy' : 'Sell';
        $linkId = 's1-' . $newId . '-entry-' . bin2hex(random_bytes(4));

        $intent = [
            'trade_id'      => $newId,
            'symbol'        => (string)$old['symbol'],
            'side'          => $bybitSide,
            'qty'           => $qty,
            'trigger_price' => (float)$old['entry_ref'],
            'tp_price'      => (float)$old['tp_init'],
            'sl_price'      => (float)$old['sl_init'],
            'purpose'       => 'entry_conditional',
            'order_link_id' => $linkId,
            'leverage'      => $old['leverage'] !== null ? (int)$old['leverage'] : null,
            'margin_mode'   => (string)($old['margin_mode'] ?? 'cross'),
        ];

        try {
            // v0.9.0-step4d: multi-account — берём адаптер по account_id из исходного trade.
            // Для legacy paper / без account_id — forCurrentMode.
            $adapter = ($accIdOld !== null)
                ? \BybitBot\Exchange\AdapterFactory::forAccount($accIdOld)
                : \BybitBot\Exchange\AdapterFactory::forCurrentMode();
            $orderId = $adapter->placeConditional($intent);
        } catch (\Throwable $e) {
            // Откат: помечаем новый trade как CANCELLED, сообщаем об ошибке.
            $pdo->prepare("UPDATE trades SET status = 'CANCELLED' WHERE id = :id")
                ->execute([':id' => $newId]);
            $response->getBody()->write(json_encode([
                'ok' => false, 'error' => 'place_failed', 'msg' => $e->getMessage(),
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        // 5. Обновляем trades.order_link_id_open / order_id_open (как делает cron_hourly).
        $pdo->prepare(
            'UPDATE trades SET order_link_id_open = :link, order_id_open = :oid WHERE id = :id'
        )->execute([':link' => $linkId, ':oid' => (string)$orderId, ':id' => $newId]);

        \BybitBot\Core\EventRecorder::tradeEvent(
            $newId,
            \BybitBot\Core\EventRecorder::INFO,
            'conditional_restored',
            [
                'from_trade_id' => $tradeId,
                'symbol'        => (string)$old['symbol'],
                'side'          => $sideLower,
                'mode'          => $mode,
                'entry_ref'     => (float)$old['entry_ref'],
                'sl_init'       => (float)$old['sl_init'],
                'tp_init'       => (float)$old['tp_init'],
                'qty'           => $qty,
                'order_link_id' => $linkId,
            ]
        );

        $response->getBody()->write(json_encode([
            'ok'           => true,
            'old_trade_id' => $tradeId,
            'new_trade_id' => $newId,
            'order_id'     => (string)$orderId,
            'order_link_id'=> $linkId,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * v0.7.3: POST /trades/{id}/close — ручное закрытие активной позиции.
     *
     * PENDING_CONDITIONAL : отмена входного ордера, trade.status = CANCELLED.
     * OPEN / AVERAGED     : paper-позиция закрывается по текущей цене (reason='manual').
     *
     * Пока работаем только в paper-режиме (см. общие правила).
     */
    public function manualClose(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tradeId = (int)($args['id'] ?? 0);
        if ($tradeId === 0) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'bad_id']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $pdo  = Database::pdo();
        // v0.9.0-step4d: добавляем account_id в SELECT для multi-account.
        $stmt = $pdo->prepare('SELECT id, mode, status, symbol, side, account_id FROM trades WHERE id = :id');
        $stmt->execute([':id' => $tradeId]);
        $trade = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($trade === false) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'not_found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $status = (string)$trade['status'];
        if (!in_array($status, ['OPEN', 'AVERAGED', 'PENDING_CONDITIONAL'], true)) {
            $response->getBody()->write(json_encode([
                'ok'    => false,
                'error' => 'wrong_status',
                'status'=> $status,
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }

        // v0.8.0.4: paper / testnet / live — выбираем адаптер по mode самой сделки,
        // а не по глобальному системному mode (сделки смешанных режимов сосуществуют).
        $tradeMode = (string)$trade['mode'];
        if (!in_array($tradeMode, ['paper', 'testnet', 'live'], true)) {
            $response->getBody()->write(json_encode([
                'ok'    => false,
                'error' => 'mode_not_supported',
                'mode'  => $tradeMode,
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // v0.9.0-step4d: выбор адаптера multi-account.
            // Для testnet/live с account_id — forAccount; для paper / legacy — forCurrentMode.
            $accIdTrade = (isset($trade['account_id']) && $trade['account_id'] !== null) ? (int)$trade['account_id'] : null;
            if (($tradeMode === 'testnet' || $tradeMode === 'live') && $accIdTrade !== null) {
                $adapter = \BybitBot\Exchange\AdapterFactory::forAccount($accIdTrade);
            } else {
                $adapter = \BybitBot\Exchange\AdapterFactory::forExchange($tradeMode);
            }

            if ($status === 'PENDING_CONDITIONAL') {
                $result = $adapter->cancelPendingManual($tradeId);
            } else {
                $result = $adapter->closePositionManual($tradeId);
            }
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'ok'    => false,
                'error' => 'exception',
                'msg'   => $e->getMessage(),
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $response->getBody()->write(json_encode([
            'ok'       => true,
            'trade_id' => $tradeId,
            'action'   => $status === 'PENDING_CONDITIONAL' ? 'cancelled' : 'closed',
            'result'   => $result,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /trades/{id}/sync — принудительная синхронизация конкретного трейда с Bybit.
     * Если позиция закрыта на бирже, но БД ещё не знает — закрывает локально.
     * Если позиция жива — обновляет qty/mark_price.
     */
    public function forceSync(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tradeId = (int)($args['id'] ?? 0);
        if ($tradeId === 0) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'bad_id']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $pdo   = Database::pdo();
        $stmt  = $pdo->prepare('SELECT id, mode, status, account_id FROM trades WHERE id = :id');
        $stmt->execute([':id' => $tradeId]);
        $trade = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($trade === false) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'not_found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $tradeMode = (string)$trade['mode'];
        if ($tradeMode === 'paper') {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'not_supported_in_paper']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $accId = (isset($trade['account_id']) && $trade['account_id'] !== null) ? (int)$trade['account_id'] : null;
            $adapter = $accId !== null
                ? \BybitBot\Exchange\AdapterFactory::forAccount($accId)
                : \BybitBot\Exchange\AdapterFactory::forExchange($tradeMode);

            // forceSyncTrade возвращает результат с action: 'closed'|'updated'
            $result = $adapter->forceSyncTrade($tradeId);
            $response->getBody()->write(json_encode(array_merge($result, ['trade_id' => $tradeId])));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * v0.8.0.13: POST /admin/cron-minute/run — принудительный запуск cron_minute.php из UI.
     * Синхронный (ждём завершения), возвращает stdout/stderr/exit_code в JSON.
     */
    public function runCronMinute(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $root = dirname(__DIR__, 3); // /src/Web/Controllers/ -> project root
        $script = $root . '/bin/cron_minute.php';
        if (!is_file($script)) {
            $response->getBody()->write(json_encode([
                'ok'    => false,
                'error' => 'script_not_found',
                'path'  => $script,
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --manual 2>&1';

        $startedAt = microtime(true);
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        $response->getBody()->write(json_encode([
            'ok'          => ($exitCode === 0),
            'exit_code'   => $exitCode,
            'duration_ms' => $durationMs,
            'output'      => implode("\n", $output),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
