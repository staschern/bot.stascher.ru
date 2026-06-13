<?php
declare(strict_types=1);

namespace BybitBot\Exchange;

use BybitBot\Bybit\Client;
use BybitBot\Bybit\Errors;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use BybitBot\Strategies\StrategyRegistry;

/**
 * Реконсилер для testnet/live режимов.
 *
 * Bybit V5 не присылает callback'ов о fills — мы сами должны опрашивать биржу
 * и приводить локальную БД в соответствие с реальным состоянием.
 *
 * Логика:
 *  - reconcileOrders():
 *      A. Локальные placed entry/avg conditional, которых НЕТ в getOpenOrders:
 *         если по символу/стороне открылась позиция (size>0) → пометить filled
 *         и (для entry) перевести trade в OPEN с созданием positions-записи;
 *         если позиции нет — пометить cancelled.
 *      B. Удалённые ордера, которых нет в локальной БД (или есть, но cancelled)
 *         → попытаться отменить на бирже (защита от "фантомных" висящих ордеров).
 *
 *  - reconcilePositions():
 *      Для каждой live/testnet позиции в БД (closed_at IS NULL):
 *         - подтянуть unrealised_pnl, mark_price, size с биржи;
 *         - записать last_price / last_price_at в positions
 *           (нужно UI для PnL-баров и колонок);
 *         - если позиции на бирже больше нет — закрыть локально, поставить
 *           trades.status = CLOSED_PROFIT / CLOSED_LOSS.
 *
 *  - reconcilePendingMarketPrices():
 *      Для PENDING_CONDITIONAL обновить orders.last_seen_price / last_seen_at,
 *      чтобы UI рисовал progress-bar SL→Entry.
 *
 * @internal Вызывается из BybitAdapter::tick(), но логически отделён, чтобы
 *           не раздувать адаптер. См. spec.md §3.2.
 */
final class LiveReconciler
{
    /** @var Client */
    private $client;

    /** @var string 'testnet'|'live' */
    private $exchange;

    /** @var BybitAdapter */
    private $adapter;

    /**
     * v0.9.0-step4: id аккаунта (bybit_accounts.id), к которому привязан тик.
     * NULL — legacy/per-exchange реконсайл (обрабатывает все ордера/позиции exchange).
     * Если задан — каждый SQL-запрос дополнительно фильтрует:
     *   - orders.account_id   = :acc
     *   - positions.account_id = :acc
     *   - trades.account_id   = :acc
     * Это позволяет cron_minute вызывать tick() поштучно для каждого аккаунта
     * и не пересекаться (важно: разные ключи → разные API-rate-limits и разные
     * множества ордеров/позиций на бирже).
     * @var int|null
     */
    private $accountId;

    public function __construct(Client $client, string $exchange, BybitAdapter $adapter, ?int $accountId = null)
    {
        $this->client    = $client;
        $this->exchange  = $exchange;
        $this->adapter   = $adapter;
        $this->accountId = $accountId;
    }

    /**
     * v0.9.0-step4: SQL-фрагмент `AND <col> = :acc` если accountId задан,
     * иначе пустая строка. Параметр :acc привязывается в вызывающем коде через bindAccount().
     */
    private function accountFilter(string $col): string
    {
        return $this->accountId !== null ? " AND {$col} = :acc" : '';
    }

    /**
     * v0.9.0-step4: добавить :acc в массив параметров если accountId задан.
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function bindAccount(array $params): array
    {
        if ($this->accountId !== null) {
            $params[':acc'] = $this->accountId;
        }
        return $params;
    }

    /**
     * Полный цикл реконсайла. Вызывается из BybitAdapter::tick().
     */
    public function reconcileAll(): void
    {
        $this->reconcileOrders();
        $this->reconcilePositions();
        $this->reconcileOrphanTrades();
        $this->reconcilePendingMarketPrices();
    }

    // ─────────────────────────────────────────────────────────────────────
    // ORDERS
    // ─────────────────────────────────────────────────────────────────────

    private function reconcileOrders(): void
    {
        $pdo = Database::pdo();

        // 1. Загружаем все active remote ордера ОДНИМ запросом (без symbol).
        $remoteOrders = $this->adapter->getOpenOrders();
        $remoteByLinkId = [];
        $remoteBySymbol = [];
        foreach ($remoteOrders as $ro) {
            $lid = (string)($ro['orderLinkId'] ?? '');
            if ($lid !== '') {
                $remoteByLinkId[$lid] = $ro;
            }
            $sym = (string)($ro['symbol'] ?? '');
            if ($sym !== '') {
                $remoteBySymbol[$sym][] = $ro;
            }
        }

        // 2. Загружаем все локальные placed ордера exchange = this->exchange.
        // v0.9.0-step4: доп. фильтр по account_id, если реконсайл запущен для конкретного аккаунта.
        $localStmt = $pdo->prepare(
            "SELECT o.id, o.trade_id, o.purpose, o.side, o.bybit_order_link_id,
                    o.bybit_order_id, o.qty, o.trigger_price, t.symbol, t.side as trade_side
             FROM orders o
             JOIN trades t ON t.id = o.trade_id
             WHERE o.exchange = :exch AND o.status = 'placed'" . $this->accountFilter('o.account_id')
        );
        $localStmt->execute($this->bindAccount([':exch' => $this->exchange]));
        $localOrders = $localStmt->fetchAll();

        $localLinkIds = [];
        foreach ($localOrders as $lo) {
            $lid = (string)($lo['bybit_order_link_id'] ?? '');
            if ($lid !== '') {
                $localLinkIds[$lid] = true;
            }
        }

        // 3. A) Локальные placed, которых нет на бирже → filled или cancelled.
        foreach ($localOrders as $lo) {
            $lid = (string)($lo['bybit_order_link_id'] ?? '');
            if ($lid === '' || isset($remoteByLinkId[$lid])) {
                continue; // ордер ещё активен на бирже
            }

            // Ордера нет на бирже. Был ли он исполнен?
            $symbol = (string)$lo['symbol'];
            $isFilled = $this->isFilledByExecutions($symbol, $lid);

            if ($isFilled) {
                $this->onConditionalFilled($lo);
            } else {
                $this->onConditionalCancelled($lo);
            }
        }

        // 4. B) Фантомные ордера на бирже без локальной placed-записи → cancel.
        // v0.9.0-step4a.2: ДОПОЛНИТЕЛЬНАЯ ЗАЩИТА от ложных cancel при multi-account:
        // берём $localLinkIds без фильтра по account_id — если ордер есть в локальной БД,
        // но по другому account_id (или NULL после миграции) — НЕ это фантом, пропускаем.
        $allLocalLinkIds = [];
        if ($this->accountId !== null) {
            $allStmt = $pdo->prepare(
                "SELECT bybit_order_link_id FROM orders
                 WHERE exchange = :exch AND status = 'placed'
                   AND bybit_order_link_id IS NOT NULL AND bybit_order_link_id <> ''"
            );
            $allStmt->execute([':exch' => $this->exchange]);
            foreach ($allStmt->fetchAll(\PDO::FETCH_COLUMN) as $linkAll) {
                $allLocalLinkIds[(string)$linkAll] = true;
            }
        } else {
            $allLocalLinkIds = $localLinkIds; // legacy — выборка и так полная
        }

        foreach ($remoteOrders as $ro) {
            $lid    = (string)($ro['orderLinkId'] ?? '');
            $oid    = (string)($ro['orderId']     ?? '');
            $symbol = (string)($ro['symbol']      ?? '');
            if ($lid === '' || isset($localLinkIds[$lid])) {
                continue;
            }

            // v0.9.0-step4a.2: если ордер есть в orders, но по другому account_id (или NULL),
            // пропускаем — это НЕ фантом.
            if (isset($allLocalLinkIds[$lid])) {
                Logger::get()->debug('live_reconciler: skip phantom check — ордер есть в БД по другому account_id', [
                    'exchange'   => $this->exchange,
                    'link_id'    => $lid,
                    'account_id' => $this->accountId,
                ]);
                continue;
            }

            // Защита: отменяем только ордера с нашим префиксом (s1-/s2-/manual-).
            if (!$this->looksLikeOurLinkId($lid)) {
                continue;
            }

            Logger::get()->warning('live_reconciler: фантомный ордер на бирже, отменяем', [
                'exchange' => $this->exchange,
                'symbol'   => $symbol,
                'link_id'  => $lid,
            ]);
            try {
                $this->client->cancelOrder($symbol, $lid);
            } catch (\Throwable $e) {
                Logger::get()->warning('live_reconciler: cancelOrder failed for phantom', [
                    'link_id' => $lid,
                    'error'   => $e->getMessage(),
                ]);
            }
            EventRecorder::event(EventRecorder::WARN, 'phantom_order_cancelled', $symbol, [
                'exchange' => $this->exchange,
                'link_id'  => $lid,
                'order_id' => $oid,
            ]);
        }

        // 5. C) v0.8.0.7: RECOVERY — trades.PENDING_CONDITIONAL, у которых локальный
        // order уже cancelled (помечен старым реконсайлом в прошлых версиях),
        // но в реальности на бирже был fill. Восстанавливаем их.
        // v0.9.0-step4: доп. фильтр по t.account_id (реконсайлим только свои PENDING_CONDITIONAL).
        $stuckStmt = $pdo->prepare(
            "SELECT t.id as trade_id, t.symbol, t.side as trade_side,
                    t.order_link_id_open,
                    o.id as order_id_local, o.purpose, o.bybit_order_link_id,
                    o.status as order_status
             FROM trades t
             LEFT JOIN orders o ON o.trade_id = t.id
                                AND o.purpose = 'entry_conditional'
                                AND o.exchange = :exch
             WHERE t.mode = :mode
               AND t.status = 'PENDING_CONDITIONAL'
               AND t.entry_real IS NULL" . $this->accountFilter('t.account_id')
        );
        $stuckStmt->execute($this->bindAccount([':exch' => $this->exchange, ':mode' => $this->exchange]));
        $stuck = $stuckStmt->fetchAll();

        foreach ($stuck as $row) {
            // Предпочитаем linkId из orders, иначе из trades.order_link_id_open.
            $linkId = (string)($row['bybit_order_link_id'] ?? '');
            if ($linkId === '') {
                $linkId = (string)($row['order_link_id_open'] ?? '');
            }
            if ($linkId === '') {
                continue;
            }
            // Если ордер жив на бирже — ничего не делаем (ждём triggers).
            if (isset($remoteByLinkId[$linkId])) {
                continue;
            }
            $symbol = (string)$row['symbol'];
            if (!$this->isFilledByExecutions($symbol, $linkId)) {
                continue;
            }

            Logger::get()->warning('live_reconciler: восстанавливаем зависший PENDING_CONDITIONAL в OPEN', [
                'trade_id' => (int)$row['trade_id'],
                'symbol'   => $symbol,
                'link_id'  => $linkId,
            ]);

            // Собираем "виртуальный" $lo для onConditionalFilled.
            // Если локальный order был помечен cancelled — возвращаем ему status='placed',
            // чтобы onConditionalFilled поставил filled.
            if (!empty($row['order_id_local']) && (string)$row['order_status'] === 'cancelled') {
                $pdo->prepare(
                    "UPDATE orders SET status = 'placed', cancelled_at = NULL WHERE id = :id"
                )->execute([':id' => (int)$row['order_id_local']]);
            }

            $lo = [
                'id'                  => (int)($row['order_id_local'] ?? 0),
                'trade_id'            => (int)$row['trade_id'],
                'purpose'             => 'entry_conditional',
                'bybit_order_link_id' => $linkId,
                'symbol'              => $symbol,
                'trade_side'          => (string)$row['trade_side'],
            ];
            try {
                $this->onConditionalFilled($lo);
                EventRecorder::tradeEvent((int)$row['trade_id'], EventRecorder::INFO, 'live_recovered_pending_to_open', [
                    'symbol'   => $symbol,
                    'link_id'  => $linkId,
                    'exchange' => $this->exchange,
                ]);
                // v0.8.0.8: сразу после recovery вызываем onPositionOpened, не ждём
                // cron_minute. Это ставит SL/trailing на бирже и снимает статичный TP
                // (важно: время реакции вместо до минуты ожидания).
                $this->triggerOnPositionOpenedAfterRecovery((int)$row['trade_id']);
            } catch (\Throwable $e) {
                Logger::get()->error('live_reconciler: recovery failed', [
                    'trade_id' => (int)$row['trade_id'],
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * v0.8.0.8: После recovery (шаг C) сразу вызвать Strategy1::onPositionOpened,
     * чтобы не ждать cron_minute и сразу поставить SL+trailing и снять статичный TP.
     *
     * Ошибки в этом вызове НЕ ломают recovery — если не получилось, cron_minute
     * подхватит через новый фильтр (trailing_trigger IS NULL).
     */
    private function triggerOnPositionOpenedAfterRecovery(int $tradeId): void
    {
        try {
            $pdo = Database::pdo();
            $trade = $pdo->prepare('SELECT * FROM trades WHERE id = :id');
            $trade->execute([':id' => $tradeId]);
            $trade = $trade->fetch();
            if (!$trade) {
                return;
            }

            // Загружаем StrategyRegistry из config/strategies.php.
            // Путь относительно bot-корня (src/Exchange/.. → src/ → root).
            $root = dirname(__DIR__, 2);
            $cfgPath = $root . '/config/strategies.php';
            if (!is_file($cfgPath)) {
                Logger::get()->warning('live_reconciler.recovery: config/strategies.php не найден, пропускаем onPositionOpened', [
                    'trade_id' => $tradeId,
                    'path'     => $cfgPath,
                ]);
                return;
            }
            $strategiesConfig = require $cfgPath;
            $registry = new StrategyRegistry($strategiesConfig);

            $stratId = (string)$trade['strategy_id'];
            $strategy = $registry->get($stratId);
            if (!$strategy) {
                return;
            }

            $depositAnchor = $this->loadDepositAnchor($this->exchange);

            $context = [
                'adapter'        => $this->adapter,
                'deposit_anchor' => $depositAnchor,
                'mode'           => $this->exchange,
            ];

            $strategy->onPositionOpened($trade, $context);

            EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'live_recovery_on_position_opened_done', [
                'exchange' => $this->exchange,
            ]);
        } catch (\Throwable $e) {
            Logger::get()->error('live_reconciler.recovery: onPositionOpened failed', [
                'trade_id' => $tradeId,
                'error'    => $e->getMessage(),
            ]);
            EventRecorder::tradeEvent($tradeId, EventRecorder::ERROR, 'live_recovery_on_position_opened_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function loadDepositAnchor(string $exchange): float
    {
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT value FROM deposit_snapshots WHERE mode = :m ORDER BY ts DESC LIMIT 1'
            );
            $stmt->execute([':m' => $exchange]);
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                return (float)$val;
            }
        } catch (\Throwable $e) {
            // fallthrough
        }
        return (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
    }

    /**
     * Проверить, был ли ордер исполнен (через execution/list).
     */
    private function isFilledByExecutions(string $symbol, string $orderLinkId): bool
    {
        try {
            $resp = $this->client->getExecutions($symbol, $orderLinkId);
            if (($resp['category'] ?? '') !== Errors::SUCCESS) {
                return false;
            }
            $list = $resp['result']['list'] ?? [];
            foreach ($list as $exec) {
                if ((string)($exec['orderLinkId'] ?? '') === $orderLinkId) {
                    $execQty = (float)($exec['execQty'] ?? 0);
                    if ($execQty > 0) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::get()->warning('live_reconciler: getExecutions failed', [
                'symbol' => $symbol, 'link_id' => $orderLinkId, 'error' => $e->getMessage(),
            ]);
        }
        return false;
    }

    /**
     * Conditional ордер исполнился.
     *  - entry_conditional: переводим trade в OPEN, создаём positions, заполняем entry_real/opened_at.
     *  - avg: переводим trade в AVERAGED, обновляем positions.qty/avg_entry_price.
     *
     * @param array $lo Локальная строка orders + symbol/trade_side из trades.
     */
    private function onConditionalFilled(array $lo): void
    {
        $pdo     = Database::pdo();
        $now     = $this->nowIso();
        $orderId = (int)$lo['id'];
        $tradeId = (int)$lo['trade_id'];
        $purpose = (string)$lo['purpose'];
        $symbol  = (string)$lo['symbol'];
        $linkId  = (string)($lo['bybit_order_link_id'] ?? '');

        // Получаем fill_price из execution/list (среднее по execQty)
        $fillPrice = null;
        $fillQty   = null;
        try {
            $resp = $this->client->getExecutions($symbol, $linkId);
            $list = $resp['result']['list'] ?? [];
            $sumQty = 0.0; $sumNotional = 0.0;
            foreach ($list as $exec) {
                if ((string)($exec['orderLinkId'] ?? '') !== $linkId) continue;
                $q = (float)($exec['execQty'] ?? 0);
                $p = (float)($exec['execPrice'] ?? 0);
                if ($q > 0 && $p > 0) {
                    $sumQty      += $q;
                    $sumNotional += $q * $p;
                }
            }
            if ($sumQty > 0) {
                $fillPrice = $sumNotional / $sumQty;
                $fillQty   = $sumQty;
            }
        } catch (\Throwable $e) {
            // fallback ниже из positions
        }

        // Помечаем ордер filled
        $pdo->prepare(
            "UPDATE orders SET status = 'filled', filled_at = :now WHERE id = :id"
        )->execute([':now' => $now, ':id' => $orderId]);

        if ($purpose === 'entry_conditional') {
            // Подтверждаем по getPositions, что позиция реально открыта
            $bybitPos = $this->fetchBybitPosition($symbol);
            if ($bybitPos === null) {
                Logger::get()->warning('live_reconciler: entry filled, но позиция на бирже не найдена', [
                    'trade_id' => $tradeId, 'symbol' => $symbol,
                ]);
                return;
            }

            // Если fill_price не вытащили из executions — берём avgPrice из позиции
            $posAvg  = (float)($bybitPos['avgPrice'] ?? 0);
            $posSize = (float)($bybitPos['size']     ?? 0);
            if ($fillPrice === null && $posAvg > 0) {
                $fillPrice = $posAvg;
            }
            if ($fillQty === null && $posSize > 0) {
                $fillQty = $posSize;
            }
            if ($fillPrice === null || $fillQty === null) {
                Logger::get()->warning('live_reconciler: не удалось определить fill_price/qty', [
                    'trade_id' => $tradeId,
                ]);
                return;
            }

            $tradeStmt = $pdo->prepare(
                'SELECT leverage, margin_mode, sl_init, tp_init FROM trades WHERE id = :id'
            );
            $tradeStmt->execute([':id' => $tradeId]);
            $tradeRow = $tradeStmt->fetch();
            $leverage  = (int)($tradeRow['leverage']    ?? 1);
            $marginMod = (string)($tradeRow['margin_mode'] ?? 'cross');
            $slInit    = isset($tradeRow['sl_init']) && $tradeRow['sl_init'] > 0 ? (float)$tradeRow['sl_init'] : null;
            $tpInit    = isset($tradeRow['tp_init']) && $tradeRow['tp_init'] > 0 ? (float)$tradeRow['tp_init'] : null;

            // 'Buy'|'Sell'. При recovery $lo['side'] может быть пуст — берём из позиции биржи.
            $side = (string)($lo['side'] ?? '');
            if ($side === '') {
                $side = (string)($bybitPos['side'] ?? '');
            }

            // Создаём positions-запись (SL/TP/trailing пока null — onPositionOpened их рассчитает).
            // v0.9.0-step4a.2: проставляем account_id из реконсайлера.
            $pdo->prepare(
                "INSERT OR IGNORE INTO positions
                 (trade_id, symbol, side, qty, qty_initial, avg_entry_price,
                  leverage, margin_mode, sl_price, tp_price, opened_at, paper, exchange,
                  last_price, last_price_at, account_id)
                 VALUES (:tid, :sym, :side, :qty, :qty, :aep, :lev, :mm, :sl, :tp,
                         :oa, 0, :exch, :lp, :lpa, :acc)"
            )->execute([
                ':tid'  => $tradeId,
                ':sym'  => $symbol,
                ':side' => $side,
                ':qty'  => $fillQty,
                ':aep'  => $fillPrice,
                ':lev'  => $leverage,
                ':mm'   => $marginMod,
                ':sl'   => $slInit,
                ':tp'   => $tpInit,
                ':oa'   => $now,
                ':exch' => $this->exchange,
                ':lp'   => $fillPrice,
                ':lpa'  => $now,
                ':acc'  => $this->accountId,
            ]);

            // Переводим trade в OPEN с entry_real/opened_at.
            // sl_current=null специально — onPositionOpened (в cron_minute) увидит null и сделает пересчёт.
            $pdo->prepare(
                "UPDATE trades
                 SET status = 'OPEN',
                     entry_real = :ep,
                     qty_initial = COALESCE(qty_initial, :qty),
                     qty_current = COALESCE(qty_current, :qty),
                     opened_at = COALESCE(opened_at, :now),
                     ignore_sl_until_open = 0
                 WHERE id = :id"
            )->execute([
                ':ep'  => $fillPrice,
                ':qty' => $fillQty,
                ':now' => $now,
                ':id'  => $tradeId,
            ]);

            EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'live_position_opened', [
                'symbol'     => $symbol,
                'side'       => $side,
                'fill_price' => $fillPrice,
                'qty'        => $fillQty,
                'exchange'   => $this->exchange,
            ]);
            Logger::get()->info('live_reconciler: trade переведён в OPEN', [
                'trade_id' => $tradeId, 'symbol' => $symbol, 'price' => $fillPrice,
            ]);

        } elseif ($purpose === 'avg') {
            // avg-ордер исполнился — обновим qty/avg_entry_price позиции, отметим averaged_at.
            $bybitPos = $this->fetchBybitPosition($symbol);
            if ($bybitPos !== null) {
                $posAvg  = (float)($bybitPos['avgPrice'] ?? 0);
                $posSize = (float)($bybitPos['size']     ?? 0);
                if ($posAvg > 0 && $posSize > 0) {
                    $pdo->prepare(
                        'UPDATE positions SET qty = :q, avg_entry_price = :aep,
                                              last_price = :lp, last_price_at = :now
                         WHERE trade_id = :tid AND closed_at IS NULL'
                    )->execute([
                        ':q'   => $posSize,
                        ':aep' => $posAvg,
                        ':lp'  => $posAvg,
                        ':now' => $now,
                        ':tid' => $tradeId,
                    ]);
                    $pdo->prepare(
                        "UPDATE trades
                         SET status = 'AVERAGED',
                             qty_current = :q,
                             averaged_at = COALESCE(averaged_at, :now)
                         WHERE id = :id AND status IN ('OPEN','AVERAGED')"
                    )->execute([':q' => $posSize, ':now' => $now, ':id' => $tradeId]);
                }
            }

            EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'live_position_averaged', [
                'symbol'   => $symbol,
                'exchange' => $this->exchange,
            ]);
        }
    }

    /**
     * Conditional исчез на бирже без fill — считаем cancelled.
     */
    private function onConditionalCancelled(array $lo): void
    {
        $pdo     = Database::pdo();
        $now     = $this->nowIso();
        $orderId = (int)$lo['id'];
        $tradeId = (int)$lo['trade_id'];
        $purpose = (string)$lo['purpose'];

        $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', cancelled_at = :now WHERE id = :id"
        )->execute([':now' => $now, ':id' => $orderId]);

        if ($purpose === 'entry_conditional') {
            $pdo->prepare(
                "UPDATE trades SET status = 'CANCELLED', closed_at = :now
                 WHERE id = :id AND status = 'PENDING_CONDITIONAL'"
            )->execute([':now' => $now, ':id' => $tradeId]);

            EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'live_pending_cancelled_remote', [
                'exchange' => $this->exchange,
                'link_id'  => $lo['bybit_order_link_id'] ?? null,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POSITIONS
    // ─────────────────────────────────────────────────────────────────────

    private function reconcilePositions(): void
    {
        $pdo = Database::pdo();
        $now = $this->nowIso();

        // v0.9.0-step4: доп. фильтр по p.account_id.
        // v0.9.2: добавлен t.entry_real для проверки прибыльности при trailing auto-close.
        $stmt = $pdo->prepare(
            "SELECT p.id, p.trade_id, p.symbol, p.side, p.qty, p.avg_entry_price,
                    p.sl_price               AS pos_sl_price,
                    p.trailing_pct           AS pos_trailing_pct,
                    p.trailing_trigger_price AS pos_trailing_trigger,
                    t.status                 AS trade_status,
                    t.sl_init                AS trade_sl_init,
                    t.trailing_pct           AS trade_trailing_pct,
                    t.trailing_trigger       AS trade_trailing_trigger,
                    t.trailing_activated_at  AS trade_trailing_activated_at,
                    t.entry_real             AS trade_entry_real,
                    t.manual_override        AS trade_manual_override
             FROM positions p
             JOIN trades t ON t.id = p.trade_id
             WHERE p.exchange = :exch AND p.closed_at IS NULL" . $this->accountFilter('p.account_id')
        );
        $stmt->execute($this->bindAccount([':exch' => $this->exchange]));
        $localPositions = $stmt->fetchAll();

        if (empty($localPositions)) return;

        // Подтянем все live-позиции одним запросом.
        $remotePositionsRaw = $this->adapter->getPositions();
        $remoteBySymbol = [];
        foreach ($remotePositionsRaw as $rp) {
            $sym  = (string)($rp['symbol'] ?? '');
            $side = (string)($rp['side']   ?? '');
            $size = (float)($rp['size']    ?? 0);
            if ($sym !== '' && $size > 0) {
                $remoteBySymbol[$sym . '_' . $side] = $rp;
            }
        }

        // v0.9.2: кэш kline по символу — один запрос на символ за весь тик.
        // Используем обе свечи (текущая + предыдущая) чтобы поймать wick в конце прошлой минуты.
        /** @var array<string, array{high:float,low:float}|null> */
        $klineCache = [];

        foreach ($localPositions as $lp) {
            $symbol = (string)$lp['symbol'];
            $side   = (string)$lp['side'];     // 'Buy'|'Sell' в наших positions
            $key    = $symbol . '_' . $side;
            $remote = $remoteBySymbol[$key] ?? null;

            if ($remote !== null) {
                // Позиция жива — обновим last_price/qty/avg_entry_price.
                $markPrice = (float)($remote['markPrice'] ?? 0);
                $rqty      = (float)($remote['size']      ?? 0);
                $ravg      = (float)($remote['avgPrice']  ?? 0);
                if ($markPrice <= 0) {
                    $markPrice = (float)($remote['lastPrice'] ?? 0);
                }

                $pdo->prepare(
                    'UPDATE positions
                     SET last_price = :lp, last_price_at = :now,
                         qty = COALESCE(:q, qty),
                         avg_entry_price = COALESCE(:aep, avg_entry_price)
                     WHERE id = :id'
                )->execute([
                    ':lp'  => $markPrice > 0 ? $markPrice : null,
                    ':now' => $now,
                    ':q'   => $rqty > 0 ? $rqty : null,
                    ':aep' => $ravg > 0 ? $ravg : null,
                    ':id'  => (int)$lp['id'],
                ]);

                // manual_override: пользователь зафиксировал SL/TP вручную — пропускаем trailing.
                if (empty($lp['trade_manual_override'])) {

                // v0.8.0.10, v0.8.0.11, v0.9.2: клиентский трейлинг + трекинг активации.
                // Источники трейлинг-параметров: сначала positions, потом trades.
                $tradeIdLp = (int)$lp['trade_id'];
                $tt = $lp['pos_trailing_trigger'] !== null
                    ? (float)$lp['pos_trailing_trigger']
                    : ($lp['trade_trailing_trigger'] !== null ? (float)$lp['trade_trailing_trigger'] : null);
                $tpct = $lp['pos_trailing_pct'] !== null
                    ? (float)$lp['pos_trailing_pct']
                    : ($lp['trade_trailing_pct'] !== null ? (float)$lp['trade_trailing_pct'] : null);
                $curSl       = $lp['pos_sl_price'] !== null ? (float)$lp['pos_sl_price'] : null;
                $alreadyActive = !empty($lp['trade_trailing_activated_at']);
                $isLong      = ($side === 'Buy');
                $entryReal   = isset($lp['trade_entry_real']) && $lp['trade_entry_real'] > 0
                    ? (float)$lp['trade_entry_real'] : null;

                // v0.9.2: kline high/low для детекции касания триггера по вику.
                // Запрашиваем только когда есть trailing-параметры.
                $klineHigh = $markPrice;
                $klineLow  = $markPrice;
                if (($tt !== null || $alreadyActive) && $markPrice > 0) {
                    if (!array_key_exists($symbol, $klineCache)) {
                        try {
                            // limit=2: [0]=текущая (формирующаяся), [1]=предыдущая (завершённая)
                            $candles = $this->adapter->getKline($symbol, '1', 2);
                            if (count($candles) >= 2) {
                                $klineCache[$symbol] = [
                                    'high' => max((float)$candles[0]['high'], (float)$candles[1]['high']),
                                    'low'  => min((float)$candles[0]['low'],  (float)$candles[1]['low']),
                                ];
                            } elseif (!empty($candles)) {
                                $klineCache[$symbol] = [
                                    'high' => (float)$candles[0]['high'],
                                    'low'  => (float)$candles[0]['low'],
                                ];
                            } else {
                                $klineCache[$symbol] = null;
                            }
                        } catch (\Throwable $e) {
                            Logger::get()->warning('live_trailing: getKline failed', [
                                'symbol' => $symbol,
                                'error'  => $e->getMessage(),
                            ]);
                            $klineCache[$symbol] = null;
                        }
                    }
                    if ($klineCache[$symbol] !== null) {
                        $klineHigh = max($markPrice, $klineCache[$symbol]['high']);
                        $klineLow  = min($markPrice, $klineCache[$symbol]['low']);
                    }
                }

                // 1) Активация: если цена (или экстремум свечи) коснулась trigger.
                //    Используем klineHigh/Low — ловим wick, пропущенный между тиками cron.
                if ($tt !== null && $markPrice > 0) {
                    $priceForActivation = $isLong ? $klineHigh : $klineLow;
                    $crossed = $isLong ? ($priceForActivation >= $tt) : ($priceForActivation <= $tt);
                    if ($crossed && !$alreadyActive) {
                        $pdo->prepare(
                            'UPDATE trades SET trailing_activated_at = :ts
                             WHERE id = :id AND trailing_activated_at IS NULL'
                        )->execute([':ts' => $now, ':id' => $tradeIdLp]);
                        $alreadyActive = true;
                        EventRecorder::tradeEvent($tradeIdLp, EventRecorder::INFO, 'trailing_activated', [
                            'symbol'     => $symbol,
                            'mark_price' => $markPrice,
                            'kline_high' => $klineHigh,
                            'kline_low'  => $klineLow,
                            'trigger'    => $tt,
                            'via_wick'   => ($isLong ? $markPrice < $tt : $markPrice > $tt),
                        ]);
                    }
                }

                // 2) Клиентский трейлинг: если активирован и есть pct.
                //    Опорная цена — экстремум свечи (лучшая цена за последние ~2 мин),
                //    чтобы SL рассчитывался от реально достигнутого уровня, а не только
                //    от текущего mark_price.
                if ($alreadyActive && $tpct !== null && $tpct > 0 && $markPrice > 0) {
                    // priceRef — экстремум: high для long, low для short.
                    $priceRef = $isLong ? $klineHigh : $klineLow;
                    $newSl = $isLong
                        ? $priceRef * (1.0 - $tpct / 100.0)
                        : $priceRef * (1.0 + $tpct / 100.0);
                    // Округление до 6 знаков чтобы не спамить API микроизменениями.
                    $newSl = round($newSl, 6);

                    // Цена уже ушла за вычисленный стоп (priceRef был выше/ниже mark_price)?
                    $priceBeyondSl = $isLong ? ($markPrice < $newSl) : ($markPrice > $newSl);

                    if ($priceBeyondSl) {
                        // Закрываем, если текущая цена в прибыли относительно entry;
                        // иначе — сбрасываем активацию и ждём нового касания триггера.
                        $isProfitable = $entryReal !== null && $entryReal > 0
                            && ($isLong ? $markPrice > $entryReal : $markPrice < $entryReal);

                        if ($isProfitable) {
                            Logger::get()->info('live_trailing: цена за стопом — авто-закрытие', [
                                'trade_id'  => $tradeIdLp,
                                'symbol'    => $symbol,
                                'mark_price'=> $markPrice,
                                'price_ref' => $priceRef,
                                'new_sl'    => $newSl,
                                'entry'     => $entryReal,
                            ]);
                            try {
                                $this->adapter->closePosition($tradeIdLp, 'trailing_sl_exceeded');
                                EventRecorder::tradeEvent($tradeIdLp, EventRecorder::INFO, 'trailing_auto_close', [
                                    'symbol'     => $symbol,
                                    'mark_price' => $markPrice,
                                    'price_ref'  => $priceRef,
                                    'new_sl'     => $newSl,
                                    'entry_real' => $entryReal,
                                ]);
                            } catch (\Throwable $e) {
                                Logger::get()->error('live_trailing: auto-close failed', [
                                    'trade_id' => $tradeIdLp,
                                    'symbol'   => $symbol,
                                    'error'    => $e->getMessage(),
                                ]);
                            }
                        } else {
                            // Сброс активации — trailing_trigger остаётся, ждём нового касания.
                            $pdo->prepare(
                                'UPDATE trades SET trailing_activated_at = NULL WHERE id = :id'
                            )->execute([':id' => $tradeIdLp]);
                            EventRecorder::tradeEvent($tradeIdLp, EventRecorder::INFO, 'trailing_reset_unprofitable', [
                                'symbol'     => $symbol,
                                'mark_price' => $markPrice,
                                'price_ref'  => $priceRef,
                                'new_sl'     => $newSl,
                                'entry_real' => $entryReal,
                            ]);
                            Logger::get()->info('live_trailing: цена за стопом убыточно — сброс активации', [
                                'trade_id'  => $tradeIdLp,
                                'symbol'    => $symbol,
                                'mark_price'=> $markPrice,
                                'new_sl'    => $newSl,
                            ]);
                        }
                        // SL на бирже не обновляем — либо уже закрылись, либо ждём нового триггера.
                    } else {
                        // Обычный путь: если SL улучшился — обновляем на бирже и в БД.
                        $improved = false;
                        if ($curSl === null || $curSl <= 0) {
                            $improved = true;
                        } elseif ($isLong && $newSl > $curSl) {
                            $improved = true;
                        } elseif (!$isLong && $newSl < $curSl) {
                            $improved = true;
                        }

                        if ($improved) {
                            try {
                                // v0.8.0.15: передаём trade_id и context в setTradingStop —
                                // адаптер сам запишет trade_event bybit_trading_stop_set/failed.
                                $ok = $this->adapter->setTradingStop($symbol, $side, [
                                    'sl_price' => $newSl,
                                    'trade_id' => $tradeIdLp,
                                    'context'  => [
                                        'purpose'    => 'live_trailing',
                                        'mark_price' => $markPrice,
                                        'price_ref'  => $priceRef,
                                        'old_sl'     => $curSl,
                                        'new_sl'     => $newSl,
                                        'pct'        => $tpct,
                                    ],
                                ]);
                            } catch (\Throwable $e) {
                                Logger::get()->warning('live_trailing: setTradingStop ошибка', [
                                    'trade_id' => $tradeIdLp,
                                    'symbol'   => $symbol,
                                    'err'      => $e->getMessage(),
                                ]);
                                $ok = false;
                            }
                            if ($ok) {
                                $pdo->prepare(
                                    'UPDATE positions SET sl_price = :sl WHERE id = :id'
                                )->execute([':sl' => $newSl, ':id' => (int)$lp['id']]);
                                $pdo->prepare(
                                    'UPDATE trades SET sl_current = :sl WHERE id = :id'
                                )->execute([':sl' => $newSl, ':id' => $tradeIdLp]);
                                Logger::get()->info('live_trailing: SL подтянут', [
                                    'trade_id'  => $tradeIdLp,
                                    'symbol'    => $symbol,
                                    'side'      => $side,
                                    'old_sl'    => $curSl,
                                    'new_sl'    => $newSl,
                                    'mark_price'=> $markPrice,
                                    'price_ref' => $priceRef,
                                    'pct'       => $tpct,
                                ]);
                            }
                        }
                    }
                }
            } // end if (empty($lp['trade_manual_override']))

            } else {
                // Позиция закрыта на бирже, а в нашей БД ещё открыта → закрываем.
                $this->closeLocalPositionAfterRemoteGone((int)$lp['id'], (int)$lp['trade_id'], $symbol, $side, $lp);
            }
        }
    }

    /**
     * Закрыть позицию локально (на бирже её больше нет).
     * PnL вытаскиваем из closed-pnl endpoint.
     */
    private function closeLocalPositionAfterRemoteGone(int $posId, int $tradeId, string $symbol, string $side, array $lp): void
    {
        $pdo = Database::pdo();
        $now = $this->nowIso();

        // Попробуем достать realised PnL.
        $realisedPnl = null;
        $closeReason = 'remote_closed';
        try {
            $resp = $this->client->getSigned('/v5/position/closed-pnl', [
                'category' => 'linear',
                'symbol'   => $symbol,
                'limit'    => 5,
            ]);
            if (($resp['category'] ?? '') === Errors::SUCCESS) {
                $list = $resp['result']['list'] ?? [];
                if (!empty($list)) {
                    $first = $list[0];
                    $realisedPnl = isset($first['closedPnl']) ? (float)$first['closedPnl'] : null;
                }
            }
        } catch (\Throwable $e) {
            // ничего страшного — закроем без PnL
        }

        $pdo->prepare(
            'UPDATE positions
             SET closed_at = :now, close_reason = :rsn, realised_pnl_usdt = :pnl
             WHERE id = :id'
        )->execute([
            ':now' => $now,
            ':rsn' => $closeReason,
            ':pnl' => $realisedPnl,
            ':id'  => $posId,
        ]);

        $tradeStatus = ($realisedPnl !== null && $realisedPnl >= 0) ? 'CLOSED_PROFIT' : 'CLOSED_LOSS';
        // Обновляем trade в двух случаях:
        //   a) trade ещё не в терминальном статусе (нормальный путь);
        //   b) trade уже CLOSED_PROFIT/CLOSED_LOSS (поставлен из closePosition()),
        //      но realized_pnl_usdt IS NULL — значит PnL ещё не был получен.
        //      В этом случае правим статус и заполняем PnL (важно для ручного закрытия).
        $pdo->prepare(
            "UPDATE trades
             SET status = :s, closed_at = COALESCE(closed_at, :now), realized_pnl_usdt = :pnl
             WHERE id = :id
               AND (
                     status NOT IN ('CLOSED_PROFIT','CLOSED_LOSS','CANCELLED')
                  OR (status IN ('CLOSED_PROFIT','CLOSED_LOSS') AND realized_pnl_usdt IS NULL)
               )"
        )->execute([':s' => $tradeStatus, ':now' => $now, ':pnl' => $realisedPnl, ':id' => $tradeId]);

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'live_position_closed_remote', [
            'symbol'      => $symbol,
            'exchange'    => $this->exchange,
            'realised_pnl'=> $realisedPnl,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ORPHAN TRADES — OPEN/AVERAGED без positions-записи
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Находит трейды в статусе OPEN/AVERAGED, у которых нет ни одной открытой
     * positions-записи (closed_at IS NULL) для данного exchange/account.
     * Такая ситуация возникает при рассинхронизации (позиция закрылась на бирже,
     * но реконсайлер не успел или positions-запись уже удалена).
     * Для каждого orphan вызываем forceSyncTrade() — он проверит биржу и закроет
     * локально если позиции нет.
     */
    private function reconcileOrphanTrades(): void
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "SELECT t.id, t.symbol FROM trades t
             WHERE t.exchange = :exch
               AND t.status IN ('OPEN','AVERAGED')
               AND t.closed_at IS NULL" .
            $this->accountFilter('t.account_id') .
            " AND NOT EXISTS (
                SELECT 1 FROM positions p
                WHERE p.trade_id = t.id
                  AND p.exchange = :exch2
                  AND p.closed_at IS NULL
            )"
        );
        $params = $this->bindAccount([':exch' => $this->exchange, ':exch2' => $this->exchange]);
        $stmt->execute($params);
        $orphans = $stmt->fetchAll();

        if (empty($orphans)) {
            return;
        }

        foreach ($orphans as $row) {
            $tradeId = (int)$row['id'];
            $symbol  = (string)$row['symbol'];
            Logger::get()->info('live_reconciler: orphan trade detected, syncing', [
                'trade_id' => $tradeId,
                'symbol'   => $symbol,
                'exchange' => $this->exchange,
            ]);
            try {
                $result = $this->adapter->forceSyncTrade($tradeId);
                Logger::get()->info('live_reconciler: orphan trade sync result', [
                    'trade_id' => $tradeId,
                    'result'   => $result,
                ]);
            } catch (\Throwable $e) {
                Logger::get()->error('live_reconciler: orphan trade sync failed', [
                    'trade_id' => $tradeId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PENDING — заполнение last_seen_price для UI
    // ─────────────────────────────────────────────────────────────────────

    private function reconcilePendingMarketPrices(): void
    {
        $pdo = Database::pdo();
        $now = $this->nowIso();

        // v0.9.0-step4: доп. фильтр по o.account_id (только свои pending).
        $stmt = $pdo->prepare(
            "SELECT DISTINCT t.symbol FROM orders o
             JOIN trades t ON t.id = o.trade_id
             WHERE o.exchange = :exch AND o.status = 'placed'
               AND o.purpose = 'entry_conditional'" . $this->accountFilter('o.account_id')
        );
        $stmt->execute($this->bindAccount([':exch' => $this->exchange]));
        $symbols = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($symbols as $symbol) {
            try {
                $tickers = $this->client->getTickers24h((string)$symbol);
                if (($tickers['category'] ?? '') !== Errors::SUCCESS) continue;
                $list = $tickers['result']['list'] ?? [];
                if (empty($list)) continue;
                $lastPrice = (float)($list[0]['lastPrice'] ?? 0);
                if ($lastPrice <= 0) continue;

                // v0.9.0-step4: UPDATE тоже фильтруем по account_id.
                $pdo->prepare(
                    "UPDATE orders
                     SET last_seen_price = :p, last_seen_at = :now
                     WHERE exchange = :exch AND status = 'placed' AND purpose = 'entry_conditional'
                       AND trade_id IN (SELECT id FROM trades WHERE symbol = :sym)" . $this->accountFilter('account_id')
                )->execute($this->bindAccount([
                    ':p'    => $lastPrice,
                    ':now'  => $now,
                    ':exch' => $this->exchange,
                    ':sym'  => $symbol,
                ]));
            } catch (\Throwable $e) {
                Logger::get()->warning('live_reconciler: getTickers24h failed', [
                    'symbol' => $symbol, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────────

    private function fetchBybitPosition(string $symbol): ?array
    {
        $positions = $this->adapter->getPositions($symbol);
        foreach ($positions as $p) {
            $size = (float)($p['size'] ?? 0);
            if ($size > 0) {
                return $p;
            }
        }
        return null;
    }

    private function looksLikeOurLinkId(string $lid): bool
    {
        return (bool)preg_match('/^(s1|s2|manual)-/', $lid);
    }

    private function nowIso(): string
    {
        $t     = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\\TH:i:s.', (int)$t) . $micro . 'Z';
    }
}
