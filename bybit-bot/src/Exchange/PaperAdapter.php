<?php
declare(strict_types=1);

namespace BybitBot\Exchange;

use BybitBot\Bybit\Client;
use BybitBot\Bybit\Errors;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use BybitBot\Core\Rounding;

/**
 * PaperAdapter — симуляция торговли без отправки реальных ордеров на Bybit.
 *
 * Реализует ExchangeAdapter. Читает публичные данные с Bybit (kline, tickers),
 * а все торговые операции симулирует локально в таблицах orders/positions
 * с exchange='paper'.
 *
 * Принцип работы (Stage 3 — единые таблицы):
 *  - placeConditional() записывает ордер в orders с exchange='paper', status='placed'
 *  - tick() — вызывается из cron_minute: проверяет триггеры и исполняет ордера
 *  - При исполнении conditional создаётся позиция в positions с exchange='paper'
 *  - SL/TP/trailing проверяются на каждом тике
 *  - paper=1 сохраняется для обратной совместимости
 *
 * ВАЖНО: также пишем в paper_orders/paper_positions для обратной совместимости
 * кода, который ещё ссылается на эти таблицы.
 *
 * См. spec.md §11 и §13a (v0.5.0).
 */
final class PaperAdapter implements ExchangeAdapter
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Создать PaperAdapter по умолчанию (mainnet read-only + paper sim).
     */
    public static function default(): self
    {
        return new self(Client::default());
    }

    // ── Справочники и состояние ─────────────────────────────

    /**
     * @return array{tickSize:float, qtyStep:float, qtyMin:float, maxLeverage:float}
     */
    public function getInstrumentInfo(string $symbol): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT tick_size, qty_step, min_order_qty, max_leverage
             FROM bybit_instruments WHERE symbol = :s LIMIT 1'
        );
        $stmt->execute([':s' => $symbol]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException("Инструмент не найден в кеше: {$symbol}. Запустите bybit:refresh-instruments");
        }
        return [
            'tickSize'    => (float)$row['tick_size'],
            'qtyStep'     => (float)$row['qty_step'],
            'qtyMin'      => (float)$row['min_order_qty'],
            'maxLeverage' => (float)$row['max_leverage'],
        ];
    }

    /**
     * @return array<int, array{open:float,high:float,low:float,close:float,start:int}>
     */
    public function getKline(string $symbol, string $interval, int $limit): array
    {
        $resp = $this->client->getKline($symbol, $interval, $limit);
        if ($resp['category'] !== Errors::SUCCESS || !isset($resp['result']['list'])) {
            Logger::get()->warning('paper_adapter: getKline failed', [
                'symbol' => $symbol, 'error' => $resp['ret_msg'] ?? 'unknown',
            ]);
            return [];
        }

        $out = [];
        foreach ($resp['result']['list'] as $candle) {
            // Bybit format: [startTime, open, high, low, close, volume, turnover]
            $out[] = [
                'start' => (int)$candle[0],
                'open'  => (float)$candle[1],
                'high'  => (float)$candle[2],
                'low'   => (float)$candle[3],
                'close' => (float)$candle[4],
            ];
        }
        return $out;
    }

    /**
     * Получить текущую цену инструмента (last price из тикера).
     */
    public function getCurrentPrice(string $symbol): ?float
    {
        $resp = $this->client->getTickers24h($symbol);
        if ($resp['category'] !== Errors::SUCCESS) {
            return null;
        }
        $list = $resp['result']['list'] ?? [];
        if (empty($list)) {
            return null;
        }
        return isset($list[0]['lastPrice']) ? (float)$list[0]['lastPrice'] : null;
    }

    /**
     * Получить минутные данные (high/low за последнюю минуту).
     *
     * @return array{high:float, low:float}|null
     */
    public function getMarketTick(string $symbol): ?array
    {
        $resp = $this->client->getKline($symbol, '1', 2);
        if ($resp['category'] !== Errors::SUCCESS || empty($resp['result']['list'])) {
            // Fallback: тикер
            $price = $this->getCurrentPrice($symbol);
            if ($price === null) {
                return null;
            }
            return ['high' => $price, 'low' => $price];
        }
        // Первая свеча — последняя завершённая минута
        $candle = $resp['result']['list'][0];
        return [
            'high' => (float)$candle[2],
            'low'  => (float)$candle[3],
        ];
    }

    /**
     * @return array{totalWalletBalance:float, availableBalance:float}
     */
    public function getWalletBalance(): array
    {
        $resp = $this->client->getWalletBalance('UNIFIED');
        if ($resp['category'] === Errors::SUCCESS && isset($resp['result']['list'][0])) {
            $acct = $resp['result']['list'][0];
            return [
                'totalWalletBalance' => (float)($acct['totalWalletBalance'] ?? 0),
                'availableBalance'   => (float)($acct['totalAvailableBalance'] ?? 0),
            ];
        }

        // Fallback — из deposit_snapshots
        $stmt = Database::pdo()->prepare(
            "SELECT value FROM deposit_snapshots WHERE mode = 'paper' ORDER BY ts DESC LIMIT 1"
        );
        $stmt->execute();
        $val = $stmt->fetchColumn();
        $balance = $val !== false ? (float)$val
            : (float)Config::get('paper_initial_deposit_usdt', null, 300);

        return [
            'totalWalletBalance' => $balance,
            'availableBalance'   => $balance,
        ];
    }

    /**
     * @return array<int, array>
     */
    public function getPositions(?string $symbol = null): array
    {
        if ($symbol !== null) {
            $stmt = Database::pdo()->prepare(
                "SELECT * FROM positions WHERE symbol = :s AND exchange = 'paper' AND closed_at IS NULL"
            );
            $stmt->execute([':s' => $symbol]);
        } else {
            $stmt = Database::pdo()->query(
                "SELECT * FROM positions WHERE exchange = 'paper' AND closed_at IS NULL"
            );
        }
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array>
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        if ($symbol !== null) {
            $stmt = Database::pdo()->prepare(
                "SELECT o.*, t.symbol as trade_symbol FROM orders o
                 JOIN trades t ON t.id = o.trade_id
                 WHERE t.symbol = :s AND o.exchange = 'paper' AND o.status IN ('placed','pending')"
            );
            $stmt->execute([':s' => $symbol]);
        } else {
            $stmt = Database::pdo()->query(
                "SELECT o.*, t.symbol as trade_symbol FROM orders o
                 JOIN trades t ON t.id = o.trade_id
                 WHERE o.exchange = 'paper' AND o.status IN ('placed','pending')"
            );
        }
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array>
     */
    public function getExecutions(string $orderLinkIdOrSymbol, int $sinceUnixMs): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT o.* FROM orders o
             JOIN trades t ON t.id = o.trade_id
             WHERE t.symbol = :s AND o.exchange = 'paper' AND o.status = 'filled'"
        );
        $stmt->execute([':s' => $orderLinkIdOrSymbol]);
        return $stmt->fetchAll();
    }

    /**
     * @return array{rate:float, nextFundingTime:int}
     */
    public function getFundingRate(string $symbol): array
    {
        $resp = $this->client->getFundingRate($symbol);
        if ($resp['category'] !== Errors::SUCCESS) {
            return ['rate' => 0.0, 'nextFundingTime' => 0];
        }
        $list = $resp['result']['list'] ?? [];
        if (empty($list)) {
            return ['rate' => 0.0, 'nextFundingTime' => 0];
        }
        return [
            'rate'            => (float)($list[0]['fundingRate'] ?? 0),
            'nextFundingTime' => (int)($list[0]['nextFundingTime'] ?? 0),
        ];
    }

    /**
     * @return array<int, array>
     */
    public function getFundingHistory(string $symbol, int $sinceUnixMs): array
    {
        return [];
    }

    /**
     * @return int Unix-time в миллисекундах
     */
    public function getServerTime(): int
    {
        return (int)(microtime(true) * 1000);
    }

    // ── Торговые операции (симуляция) ────────────────────────

    /**
     * Разместить conditional ордер в симуляторе.
     * Записывает в orders с exchange='paper' + paper_orders (обратная совместимость).
     *
     * @param array $params {
     *   trade_id, symbol, side, qty, trigger_price, tp_price, sl_price,
     *   purpose, order_link_id, leverage, margin_mode
     * }
     * @return string Локальный order ID
     */
    public function placeConditional(array $params): string
    {
        $pdo = Database::pdo();
        $now = self::nowIso();

        $tradeId     = (int)$params['trade_id'];
        $side        = (string)($params['side'] ?? 'Buy');
        $qty         = (float)($params['qty'] ?? 0);
        $triggerPx   = (float)($params['trigger_price'] ?? 0);
        $slPrice     = isset($params['sl_price']) ? (float)$params['sl_price'] : null;
        $tpPrice     = isset($params['tp_price']) ? (float)$params['tp_price'] : null;
        $purpose     = (string)($params['purpose'] ?? 'entry_conditional');
        $linkId      = (string)($params['order_link_id'] ?? ('paper-' . $tradeId . '-' . bin2hex(random_bytes(4))));

        // 1. Записываем в orders (единая таблица, exchange='paper')
        $stmt = $pdo->prepare(
            'INSERT INTO orders
             (trade_id, purpose, side, order_type, qty, trigger_price, sl_price,
              reduce_only, bybit_order_link_id, status, placed_at, paper, exchange)
             VALUES (:tid, :purpose, :side, :otype, :qty, :tprice, :slp,
                     :ro, :linkid, :status, :pat, 1, \'paper\')'
        );
        $stmt->execute([
            ':tid'     => $tradeId,
            ':purpose' => $purpose,
            ':side'    => $side,
            ':otype'   => 'Conditional',
            ':qty'     => $qty,
            ':tprice'  => $triggerPx,
            ':slp'     => $slPrice,
            ':ro'      => 0,
            ':linkid'  => $linkId,
            ':status'  => 'placed',
            ':pat'     => $now,
        ]);
        $orderId = (int)$pdo->lastInsertId();

        // 2. Также пишем в paper_orders для обратной совместимости
        $stmtPaper = $pdo->prepare(
            'INSERT OR IGNORE INTO paper_orders (trade_id, kind, side, trigger_price, limit_price, qty, status)
             VALUES (:tid, :kind, :side, :tp, :lp, :qty, :status)'
        );
        $stmtPaper->execute([
            ':tid'    => $tradeId,
            ':kind'   => 'conditional_open',
            ':side'   => $side,
            ':tp'     => $triggerPx,
            ':lp'     => $tpPrice,
            ':qty'    => $qty,
            ':status' => 'pending',
        ]);

        EventRecorder::event(EventRecorder::INFO, 'paper_conditional_placed', (string)($params['symbol'] ?? ''), [
            'trade_id'      => $tradeId,
            'order_id'      => $orderId,
            'side'          => $side,
            'trigger_price' => $triggerPx,
            'qty'           => $qty,
            'tp'            => $tpPrice,
            'sl'            => $slPrice,
        ]);

        Logger::get()->info('paper_adapter: conditional placed', [
            'order_id' => $orderId,
            'symbol'   => $params['symbol'] ?? '',
            'trigger'  => $triggerPx,
        ]);

        return (string)$orderId;
    }

    /**
     * Отменить ордер в симуляторе.
     *
     * @param string $orderIdOrLink ID или orderLinkId
     * @return bool
     */
    public function cancelOrder(string $orderIdOrLink): bool
    {
        $pdo = Database::pdo();
        $now = self::nowIso();

        // Обновить в orders
        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', cancelled_at = :now
             WHERE (bybit_order_link_id = :lid OR CAST(id AS TEXT) = :idstr)
               AND exchange = 'paper' AND status IN ('placed','pending')"
        );
        $stmt->execute([':now' => $now, ':lid' => $orderIdOrLink, ':idstr' => $orderIdOrLink]);
        $updated = $stmt->rowCount() > 0;

        // Также в paper_orders
        $pdo->prepare(
            "UPDATE paper_orders SET status = 'cancelled', filled_at = :now
             WHERE status = 'pending'"
        )->execute([':now' => $now]);

        return $updated;
    }

    /**
     * Изменить ордер (не поддерживается в paper-симуляторе).
     */
    public function amendOrder(string $orderId, array $fields): bool
    {
        Logger::get()->warning('paper_adapter: amendOrder не реализован');
        return false;
    }

    /**
     * Установить SL/TP/trailing на paper-позиции.
     *
     * @param string $symbol
     * @param string $side
     * @param array  $fields {sl_price?, tp_price?, trailing_pct?, trailing_trigger_price?}
     */
    public function setTradingStop(string $symbol, string $side, array $fields): bool
    {
        // Обновляем в positions (единая таблица)
        $stmt = Database::pdo()->prepare(
            "UPDATE positions
             SET sl_price               = COALESCE(:sl, sl_price),
                 tp_price               = COALESCE(:tp, tp_price),
                 trailing_pct           = COALESCE(:trpct, trailing_pct),
                 trailing_trigger_price = COALESCE(:trtrig, trailing_trigger_price)
             WHERE symbol = :sym AND exchange = 'paper' AND closed_at IS NULL"
        );
        $stmt->execute([
            ':sl'     => isset($fields['sl_price'])               ? (float)$fields['sl_price']               : null,
            ':tp'     => isset($fields['tp_price'])               ? (float)$fields['tp_price']               : null,
            ':trpct'  => isset($fields['trailing_pct'])           ? (float)$fields['trailing_pct']           : null,
            ':trtrig' => isset($fields['trailing_trigger_price'])  ? (float)$fields['trailing_trigger_price']  : null,
            ':sym'    => $symbol,
        ]);

        // Также обновляем в paper_positions для обратной совместимости
        Database::pdo()->prepare(
            'UPDATE paper_positions
             SET sl_price = COALESCE(:sl, sl_price),
                 tp_price = COALESCE(:tp, tp_price),
                 trailing_pct = COALESCE(:trpct, trailing_pct),
                 trailing_trigger_price = COALESCE(:trtrig, trailing_trigger_price)
             WHERE symbol = :sym AND closed_at IS NULL'
        )->execute([
            ':sl'     => isset($fields['sl_price'])               ? (float)$fields['sl_price']               : null,
            ':tp'     => isset($fields['tp_price'])               ? (float)$fields['tp_price']               : null,
            ':trpct'  => isset($fields['trailing_pct'])           ? (float)$fields['trailing_pct']           : null,
            ':trtrig' => isset($fields['trailing_trigger_price'])  ? (float)$fields['trailing_trigger_price']  : null,
            ':sym'    => $symbol,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Установить плечо — в paper-режиме only no-op.
     */
    public function setLeverage(string $symbol, int $value): bool
    {
        Logger::get()->info('paper_adapter: setLeverage (no-op)', ['symbol' => $symbol, 'leverage' => $value]);
        return true;
    }

    /**
     * Переключить margin mode — в paper-режиме no-op.
     */
    public function switchMarginMode(string $crossOrIsolated): bool
    {
        Logger::get()->info('paper_adapter: switchMarginMode (no-op)', ['mode' => $crossOrIsolated]);
        return true;
    }

    /**
     * Reduce-only лимит-ордер (для S2 60%-TP).
     * В paper-режиме записывается в orders/paper_orders.
     *
     * @return string Локальный ID
     */
    public function placeReduceOnlyLimit(
        string $symbol,
        string $side,
        float $qty,
        float $price,
        string $orderLinkId
    ): string {
        $pdo = Database::pdo();

        // Найдём trade_id по символу (через positions)
        $stmt = $pdo->prepare(
            "SELECT p.trade_id FROM positions p
             JOIN trades t ON t.id = p.trade_id
             WHERE t.symbol = :s AND p.exchange = 'paper' AND p.closed_at IS NULL LIMIT 1"
        );
        $stmt->execute([':s' => $symbol]);
        $tradeId = $stmt->fetchColumn();

        if ($tradeId === false) {
            throw new \RuntimeException("Нет открытой paper-позиции для символа {$symbol}");
        }

        $now = self::nowIso();

        // Записываем в orders
        $stmt2 = $pdo->prepare(
            'INSERT INTO orders
             (trade_id, purpose, side, order_type, qty, price, trigger_price, reduce_only,
              bybit_order_link_id, status, placed_at, paper, exchange)
             VALUES (:tid, :purpose, :side, :otype, :qty, :price, :tprice, 1, :linkid, :status, :pat, 1, \'paper\')'
        );
        $stmt2->execute([
            ':tid'     => (int)$tradeId,
            ':purpose' => 'tp_partial',
            ':side'    => $side,
            ':otype'   => 'Limit',
            ':qty'     => $qty,
            ':price'   => $price,
            ':tprice'  => $price,
            ':linkid'  => $orderLinkId,
            ':status'  => 'placed',
            ':pat'     => $now,
        ]);

        // Обратная совместимость: paper_orders
        $pdo->prepare(
            'INSERT INTO paper_orders (trade_id, kind, side, trigger_price, limit_price, qty, status)
             VALUES (:tid, :kind, :side, :tp, :lp, :qty, :status)'
        )->execute([
            ':tid'    => (int)$tradeId,
            ':kind'   => 'part_tp',
            ':side'   => $side,
            ':tp'     => $price,
            ':lp'     => $price,
            ':qty'    => $qty,
            ':status' => 'pending',
        ]);

        return (string)$pdo->lastInsertId();
    }

    // ── Тик симулятора (вызывается из cron_minute) ──────────

    /**
     * Обработать один тик симулятора для всех pending paper-ордеров.
     *
     * @return array<int, array>
     */
    public function tick(): array
    {
        $events = [];
        $events = array_merge($events, $this->tickConditionals());
        $events = array_merge($events, $this->tickPositions());
        return $events;
    }

    /**
     * Проверить pending conditional ордера (из orders с exchange='paper').
     *
     * @return array
     */
    private function tickConditionals(): array
    {
        $events = [];
        $pdo    = Database::pdo();

        $rows = $pdo->query(
            "SELECT o.*, t.symbol, t.side as trade_side
             FROM orders o
             JOIN trades t ON t.id = o.trade_id
             WHERE o.exchange = 'paper' AND o.status = 'placed' AND o.purpose = 'entry_conditional'"
        )->fetchAll();

        // Подготовим один препар для обновления last_seen_* — повторно не готовим.
        $updLastSeen = Database::pdo()->prepare(
            'UPDATE orders SET last_seen_price = :p, last_seen_at = :ts WHERE id = :id'
        );

        foreach ($rows as $row) {
            $symbol = (string)$row['symbol'];
            $currentPrice = $this->getCurrentPrice($symbol);
            if ($currentPrice === null) {
                continue;
            }

            $triggerPrice = (float)$row['trigger_price'];
            $side         = (string)$row['side'];
            $tradeId      = (int)$row['trade_id'];
            $orderId      = (int)$row['id'];

            // Обновим кеш свежей цены — используется UI для «% пути» без доп. API.
            $updLastSeen->execute([
                ':p'  => $currentPrice,
                ':ts' => self::nowIso(),
                ':id' => $orderId,
            ]);

            // Long: сработает если цена >= trigger; Short: сработает если цена <= trigger
            $triggered = false;
            if ($side === 'Buy' && $currentPrice >= $triggerPrice) {
                $triggered = true;
            } elseif ($side === 'Sell' && $currentPrice <= $triggerPrice) {
                $triggered = true;
            }

            if ($triggered) {
                $this->fillConditional($orderId, $tradeId, $symbol, $side, $triggerPrice, (float)$row['qty']);
                $events[] = [
                    'type'       => 'conditional_filled',
                    'trade_id'   => $tradeId,
                    'symbol'     => $symbol,
                    'fill_price' => $triggerPrice,
                    'qty'        => $row['qty'],
                    'order_id'   => $orderId,
                ];

                Logger::get()->info('paper_adapter: conditional filled', [
                    'trade_id' => $tradeId, 'symbol' => $symbol, 'price' => $triggerPrice,
                ]);
            }
        }

        return $events;
    }

    /**
     * Создать paper-позицию после исполнения conditional.
     *
     * v0.7.0: после создания позиции выполняем §6.2 (пересчёт SL), §6.3 (trailing) и §6.4 (усреднение).
     */
    private function fillConditional(
        int $orderId,
        int $tradeId,
        string $symbol,
        string $side,
        float $fillPrice,
        float $qty
    ): void {
        $pdo = Database::pdo();
        $now = self::nowIso();

        // Обновить orders
        $pdo->prepare(
            "UPDATE orders SET status = 'filled', filled_at = :now WHERE id = :id"
        )->execute([':now' => $now, ':id' => $orderId]);

        // Обновить paper_orders (обратная совместимость)
        $pdo->prepare(
            "UPDATE paper_orders SET status = 'filled', filled_at = :now, filled_price = :fp
             WHERE trade_id = :tid AND status = 'pending'"
        )->execute([':now' => $now, ':fp' => $fillPrice, ':tid' => $tradeId]);

        // Загрузить исходные параметры сделки
        $tradeStmt = $pdo->prepare(
            'SELECT leverage, margin_mode, sl_init, tp_init, entry_ref, p_for_strategy_calc, signal_target_pct
             FROM trades WHERE id = :id'
        );
        $tradeStmt->execute([':id' => $tradeId]);
        $tradeRow = $tradeStmt->fetch();

        $leverage  = (int)($tradeRow['leverage']    ?? 1);
        $marginMod = (string)($tradeRow['margin_mode'] ?? 'cross');
        $slInit    = isset($tradeRow['sl_init']) && $tradeRow['sl_init'] > 0 ? (float)$tradeRow['sl_init'] : null;
        $tpInit    = isset($tradeRow['tp_init']) && $tradeRow['tp_init'] > 0 ? (float)$tradeRow['tp_init'] : null;
        $entryRef  = isset($tradeRow['entry_ref']) && $tradeRow['entry_ref'] > 0 ? (float)$tradeRow['entry_ref'] : $fillPrice;

        // p для расчётов: p_for_strategy_calc → signal_target_pct → вывести из sl_init/entry_ref
        $p = null;
        if (isset($tradeRow['p_for_strategy_calc']) && $tradeRow['p_for_strategy_calc'] > 0) {
            $p = (float)$tradeRow['p_for_strategy_calc'];
        } elseif (isset($tradeRow['signal_target_pct']) && $tradeRow['signal_target_pct'] > 0) {
            $p = abs((float)$tradeRow['signal_target_pct']);
        } elseif ($slInit !== null && $entryRef > 0) {
            $p = abs(($slInit - $entryRef) / $entryRef) * 100.0;
        }

        $sign = ($side === 'Buy') ? 1.0 : -1.0; // long=+1, short=-1

        // §6.2: SL_real = P_real − (P_real × sign × p × 2 × market_coef) / 100
        $marketCoef = (float)Config::get('market_coef', null, 1.35);
        $slReal     = null;
        if ($p !== null && $p > 0) {
            $slReal = $fillPrice - ($fillPrice * $sign * $p * 2.0 * $marketCoef) / 100.0;
        }

        // §6.3: trailing_pct и trigger_price
        $trailingPct     = null;
        $trailingTrigger = null;
        if ($p !== null && $p > 0) {
            $movementCoef = (int)floor($p / 4.0) + 1; // p∈[0,4)→1, [4,8)→2, [8,16)→3 …
            if ($movementCoef < 1) { $movementCoef = 1; }
            $trailingPctRaw  = $p / $movementCoef - 0.3;
            $trailingPct     = floor($trailingPctRaw * 10.0) / 10.0;
            if ($trailingPct < 0.1) { $trailingPct = 0.1; }
            $trailingTrigger = $fillPrice + ($fillPrice * $sign * ($p / $movementCoef)) / 100.0;
        }

        // §6.4: ордер усреднения
        $avgPrice = null;
        $avgQty   = null;
        if ($p !== null && $p > 0) {
            $avgPrice = $fillPrice - ($fillPrice * $sign * $p * 1.5 * $marketCoef) / 100.0;
            $avgQty   = $qty * 2.0 * $marketCoef;
            // Округления к tickSize/qtyStep — по возможности
            $infoStmt = $pdo->prepare('SELECT tick_size, qty_step FROM bybit_instruments WHERE symbol = :s');
            $infoStmt->execute([':s' => $symbol]);
            $instr = $infoStmt->fetch();
            if ($instr) {
                $tickSize = (float)$instr['tick_size'];
                $qtyStep  = (float)$instr['qty_step'];
                if ($tickSize > 0) {
                    // long: avg_price < P_real → округляем вниз; short: avg_price > P_real → вверх
                    $avgPrice = ($sign > 0)
                        ? (floor($avgPrice / $tickSize) * $tickSize)
                        : (ceil($avgPrice  / $tickSize) * $tickSize);
                }
                if ($qtyStep > 0) {
                    $avgQty = ceil($avgQty / $qtyStep) * $qtyStep;
                }
            }
        }

        // Итоговые значения SL/TP для positions:
        // SL = SL_real (если посчитан), иначе sl_init
        // TP в paper-симуляторе заменяется на trailing_trigger (TS вместо TP)
        $slPriceFinal = $slReal !== null ? $slReal : $slInit;
        $tpPriceFinal = $trailingTrigger !== null ? $trailingTrigger : $tpInit;

        // Создать запись в positions (единая таблица)
        $stmt = $pdo->prepare(
            "INSERT OR IGNORE INTO positions
             (trade_id, symbol, side, qty, qty_initial, avg_entry_price, leverage, margin_mode,
              sl_price, tp_price, trailing_pct, trailing_trigger_price, opened_at, paper, exchange)
             VALUES (:tid, :sym, :side, :qty, :qtyini, :aep, :lev, :mm, :sl, :tp, :tpct, :ttrg, :oa, 1, 'paper')"
        );
        $stmt->execute([
            ':tid'    => $tradeId,
            ':sym'    => $symbol,
            ':side'   => $side,
            ':qty'    => $qty,
            ':qtyini' => $qty,
            ':aep'    => $fillPrice,
            ':lev'    => $leverage,
            ':mm'     => $marginMod,
            ':sl'     => $slPriceFinal,
            ':tp'     => $tpPriceFinal,
            ':tpct'   => $trailingPct,
            ':ttrg'   => $trailingTrigger,
            ':oa'     => $now,
        ]);

        // Обратная совместимость: paper_positions
        $stmt2 = $pdo->prepare(
            'INSERT OR IGNORE INTO paper_positions
             (trade_id, symbol, side, qty, qty_initial, avg_entry_price, leverage, margin_mode,
              sl_price, tp_price, trailing_pct, trailing_trigger_price, opened_at)
             VALUES (:tid, :sym, :side, :qty, :qtyini, :aep, :lev, :mm, :sl, :tp, :tpct, :ttrg, :oa)'
        );
        $stmt2->execute([
            ':tid'    => $tradeId,
            ':sym'    => $symbol,
            ':side'   => $side,
            ':qty'    => $qty,
            ':qtyini' => $qty,
            ':aep'    => $fillPrice,
            ':lev'    => $leverage,
            ':mm'     => $marginMod,
            ':sl'     => $slPriceFinal,
            ':tp'     => $tpPriceFinal,
            ':tpct'   => $trailingPct,
            ':ttrg'   => $trailingTrigger,
            ':oa'     => $now,
        ]);

        // Обновить trade: факт. вход + пересчитанные SL_current, trailing, avg
        $pdo->prepare(
            "UPDATE trades
             SET status = 'OPEN',
                 entry_real = :ep,
                 qty_initial = COALESCE(qty_initial, :qty),
                 qty_current = COALESCE(qty_current, :qty),
                 sl_current  = COALESCE(:slr, sl_current),
                 trailing_pct = COALESCE(:tpct, trailing_pct),
                 trailing_trigger = COALESCE(:ttrg, trailing_trigger),
                 avg_price   = COALESCE(:avgp, avg_price),
                 qty_avg     = COALESCE(:avgq, qty_avg),
                 p_for_strategy_calc = COALESCE(:pcalc, p_for_strategy_calc),
                 opened_at   = :now,
                 ignore_sl_until_open = 0
             WHERE id = :id"
        )->execute([
            ':ep'    => $fillPrice,
            ':qty'   => $qty,
            ':slr'   => $slReal,
            ':tpct'  => $trailingPct,
            ':ttrg'  => $trailingTrigger,
            ':avgp'  => $avgPrice,
            ':avgq'  => $avgQty,
            ':pcalc' => $p,
            ':now'   => $now,
            ':id'    => $tradeId,
        ]);

        // Создать «placed» avg-ордер (сработает в tickPositions, когда цена дойдёт до trigger_price; §7 пока не реализован)
        if ($avgPrice !== null && $avgQty !== null) {
            $avgSide = $side; // добавление в ту же сторону
            $avgLink = 's1-' . $tradeId . '-avg-' . bin2hex(random_bytes(3));
            $pdo->prepare(
                "INSERT INTO orders
                 (trade_id, exchange, paper, purpose, status, side, order_type, qty, trigger_price,
                  bybit_order_link_id, placed_at)
                 VALUES (:tid, 'paper', 1, 'avg', 'placed', :side, 'Conditional', :qty, :trg,
                         :link, :now)"
            )->execute([
                ':tid'  => $tradeId,
                ':side' => $avgSide,
                ':qty'  => $avgQty,
                ':trg'  => $avgPrice,
                ':link' => $avgLink,
                ':now'  => $now,
            ]);
        }

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'paper_position_opened', [
            'symbol'           => $symbol,
            'side'             => $side,
            'fill_price'       => $fillPrice,
            'qty'              => $qty,
            'sl_real'          => $slReal,
            'trailing_pct'     => $trailingPct,
            'trailing_trigger' => $trailingTrigger,
            'avg_price'        => $avgPrice,
            'avg_qty'          => $avgQty,
            'p_used'           => $p,
        ]);
    }

    /**
     * Проверить открытые paper-позиции на SL/TP/trailing.
     *
     * @return array
     */
    private function tickPositions(): array
    {
        $events = [];
        $pdo    = Database::pdo();

        $rows = $pdo->query(
            "SELECT * FROM positions WHERE exchange = 'paper' AND closed_at IS NULL"
        )->fetchAll();

        // v0.7.0 #4: препар для записи текущей цены в positions — для UI PnL/progress.
        $updLastPrice = $pdo->prepare(
            'UPDATE positions SET last_price = :p, last_price_at = :ts WHERE id = :id'
        );

        foreach ($rows as $pos) {
            $symbol = (string)$pos['symbol'];
            $currentPrice = $this->getCurrentPrice($symbol);
            if ($currentPrice === null) {
                continue;
            }

            // v0.7.0 #4: сохранить свежую цену как кеш для рендера без API вызовов.
            $updLastPrice->execute([
                ':p'  => $currentPrice,
                ':ts' => self::nowIso(),
                ':id' => (int)$pos['id'],
            ]);

            $side    = (string)$pos['side']; // 'Buy'|'Sell'
            $isLong  = ($side === 'Buy');
            $tradeId = (int)$pos['trade_id'];
            $posId   = (int)$pos['id'];

            // Trailing trigger
            $trailingTrigger = isset($pos['trailing_trigger_price']) && $pos['trailing_trigger_price'] !== null
                ? (float)$pos['trailing_trigger_price'] : null;
            $trailingPct = isset($pos['trailing_pct']) && $pos['trailing_pct'] !== null
                ? (float)$pos['trailing_pct'] : null;

            $effectiveSl = isset($pos['sl_price']) && $pos['sl_price'] !== null
                ? (float)$pos['sl_price'] : null;

            // Если trailing активирован
            // v0.8.0.10: при первом пересечении trigger ставим trades.trailing_activated_at = now
            $trailingActivatedNow = false;
            if ($trailingPct !== null && $trailingTrigger !== null) {
                if ($isLong && $currentPrice >= $trailingTrigger) {
                    $trailingSl = $currentPrice * (1 - $trailingPct / 100);
                    if ($effectiveSl === null || $trailingSl > $effectiveSl) {
                        $effectiveSl = $trailingSl;
                    }
                    $trailingActivatedNow = true;
                } elseif (!$isLong && $currentPrice <= $trailingTrigger) {
                    $trailingSl = $currentPrice * (1 + $trailingPct / 100);
                    if ($effectiveSl === null || $trailingSl < $effectiveSl) {
                        $effectiveSl = $trailingSl;
                    }
                    $trailingActivatedNow = true;
                }
            }
            if ($trailingActivatedNow) {
                // Проставляем отметку в trades — только если пусто (первое пересечение).
                $pdo->prepare(
                    'UPDATE trades SET trailing_activated_at = :ts
                     WHERE id = :id AND trailing_activated_at IS NULL'
                )->execute([
                    ':ts' => self::nowIso(),
                    ':id' => $tradeId,
                ]);
            }

            // ── v0.7.2 §7: проверяем триггер усреднения ──
            $avgEvent = $this->tryFillAveraging($tradeId, $posId, $pos, $currentPrice);
            if ($avgEvent !== null) {
                $events[] = $avgEvent;
                // После усреднения перечитаем актуальную позицию
                $freshStmt = $pdo->prepare('SELECT * FROM positions WHERE id = :id');
                $freshStmt->execute([':id' => $posId]);
                $fresh = $freshStmt->fetch();
                if ($fresh) {
                    $pos = $fresh;
                    $effectiveSl     = isset($pos['sl_price']) ? (float)$pos['sl_price'] : null;
                    $trailingTrigger = isset($pos['trailing_trigger_price']) ? (float)$pos['trailing_trigger_price'] : null;
                    $trailingPct     = isset($pos['trailing_pct']) ? (float)$pos['trailing_pct'] : null;
                }
            }

            // Проверяем SL
            if ($effectiveSl !== null) {
                $slHit = $isLong
                    ? ($currentPrice <= $effectiveSl)
                    : ($currentPrice >= $effectiveSl);

                if ($slHit) {
                    $pnl = $this->calcPnl($pos, $effectiveSl);
                    $this->closePaperPosition($posId, $tradeId, $effectiveSl, 'sl', $pnl);
                    $events[] = [
                        'type'     => 'sl_hit',
                        'trade_id' => $tradeId,
                        'symbol'   => $symbol,
                        'price'    => $effectiveSl,
                        'pnl'      => $pnl,
                    ];
                    continue;
                }
            }

            // Проверяем TP
            $tpPrice = isset($pos['tp_price']) && $pos['tp_price'] !== null
                ? (float)$pos['tp_price'] : null;
            if ($tpPrice !== null) {
                $tpHit = $isLong
                    ? ($currentPrice >= $tpPrice)
                    : ($currentPrice <= $tpPrice);

                if ($tpHit) {
                    $pnl = $this->calcPnl($pos, $tpPrice);
                    $this->closePaperPosition($posId, $tradeId, $tpPrice, 'tp', $pnl);
                    $events[] = [
                        'type'     => 'tp_hit',
                        'trade_id' => $tradeId,
                        'symbol'   => $symbol,
                        'price'    => $tpPrice,
                        'pnl'      => $pnl,
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * v0.7.2 §7: исполнить avg-ордер, если цена прошла триггер.
     *
     * - Q2 = qty_avg из trades, P2 = trigger_price avg-ордера (как market fill)
     * - §7.1 P_BE с комиссиями (provisional avg(P1,P2) для fees_close)
     * - §7.2 trailing_pct=1.0, trigger=P_BE+sign×P_BE×2/100
     * - §7.3 SL_post_avg = P_BE − sign×max_loss/(Q1+Q2). Не хуже текущего.
     *
     * @return array|null Событие avg_filled или null если не сработало.
     */
    private function tryFillAveraging(int $tradeId, int $posId, array $pos, float $currentPrice): ?array
    {
        $pdo = Database::pdo();

        // Ищем живой avg-ордер
        $ordStmt = $pdo->prepare(
            "SELECT * FROM orders
             WHERE trade_id = :tid AND purpose = 'avg' AND exchange = 'paper'
               AND status IN ('placed','pending','submitted')
             ORDER BY id DESC LIMIT 1"
        );
        $ordStmt->execute([':tid' => $tradeId]);
        $ord = $ordStmt->fetch();
        if (!$ord) {
            return null;
        }

        $triggerPrice = isset($ord['trigger_price']) ? (float)$ord['trigger_price'] : 0.0;
        if ($triggerPrice <= 0) {
            return null;
        }

        $isLong = ((string)$pos['side'] === 'Buy');
        $sign   = $isLong ? 1.0 : -1.0;

        // Триггер усреднения всегда в плохую сторону от entry:
        //   long  → trigger < entry, срабатывает при current <= trigger
        //   short → trigger > entry, срабатывает при current >= trigger
        $hit = $isLong
            ? ($currentPrice <= $triggerPrice)
            : ($currentPrice >= $triggerPrice);
        if (!$hit) {
            return null;
        }

        // Данные позиции до усреднения
        $q1 = (float)$pos['qty'];
        $p1 = (float)$pos['avg_entry_price'];
        $q2 = (float)$ord['qty'];
        $p2 = $triggerPrice; // market fill по триггеру

        if ($q1 <= 0 || $q2 <= 0) {
            return null;
        }

        $qNew    = $q1 + $q2;
        $avgRaw  = ($p1 * $q1 + $p2 * $q2) / $qNew; // взвешенная, без комиссий.

        // §7.1: P_BE с комиссиями. provisional fee_close использует avgRaw.
        $takerFee  = (float)Config::get('taker_fee_pct', null, 0.055) / 100.0;
        $feesOpen  = $q1 * $p1 * $takerFee + $q2 * $p2 * $takerFee;
        $feesClose = $qNew * $avgRaw * $takerFee;
        $funding   = 0.0; // в paper-симуляторе фандинга пока нет
        $pBe = ($p1 * $q1 + $p2 * $q2 + $sign * ($feesOpen + $feesClose + $funding)) / $qNew;

        // §7.2: trailing после усреднения — жёсткие константы
        $trailingPctNew     = 1.0;
        $trailingTriggerNew = $pBe + $sign * $pBe * 2.0 / 100.0;

        // §7.3: SL_post_avg от anchor-депозита. Для paper — берём paper_initial_deposit.
        $depositAnchor = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
        $maxLossUsdt   = 0.08 * $depositAnchor;
        $slDistance    = $maxLossUsdt / $qNew;
        $slPostAvg     = $pBe - $sign * $slDistance;

        // SL не хуже текущего (long: старый ниже нового — плохо; short: выше — плохо)
        $currentSl = isset($pos['sl_price']) ? (float)$pos['sl_price'] : null;
        $slFinal   = $slPostAvg;
        if ($currentSl !== null) {
            if ($isLong && $currentSl > $slPostAvg) {
                $slFinal = $currentSl;
            } elseif (!$isLong && $currentSl < $slPostAvg) {
                $slFinal = $currentSl;
            }
        }

        // Округление к tick_size
        $infoStmt = $pdo->prepare('SELECT tick_size FROM bybit_instruments WHERE symbol = :s');
        $infoStmt->execute([':s' => (string)$pos['symbol']]);
        $instr    = $infoStmt->fetch();
        $tickSize = $instr ? (float)$instr['tick_size'] : 0.0;
        if ($tickSize > 0) {
            // SL: в лучшую сторону для позиции (long: ceil, short: floor)
            $slFinal   = $isLong
                ? (ceil($slFinal  / $tickSize) * $tickSize)
                : (floor($slFinal / $tickSize) * $tickSize);
            $trailingTriggerNew = $isLong
                ? (ceil($trailingTriggerNew / $tickSize) * $tickSize)
                : (floor($trailingTriggerNew / $tickSize) * $tickSize);
        }

        $now = self::nowIso();

        // ── Запись ──
        $pdo->beginTransaction();
        try {
            // 1) positions
            $pdo->prepare(
                "UPDATE positions SET
                    qty                    = :qn,
                    avg_entry_price        = :avgr,
                    sl_price               = :sl,
                    tp_price               = :tt,
                    trailing_pct           = :tpct,
                    trailing_trigger_price = :tt
                 WHERE id = :id"
            )->execute([
                ':qn'   => $qNew,
                ':avgr' => $avgRaw,
                ':sl'   => $slFinal,
                ':tt'   => $trailingTriggerNew,
                ':tpct' => $trailingPctNew,
                ':id'   => $posId,
            ]);

            // 2) paper_positions (legacy)
            $pdo->prepare(
                "UPDATE paper_positions SET
                    qty                    = :qn,
                    avg_entry_price        = :avgr,
                    sl_price               = :sl,
                    tp_price               = :tt,
                    trailing_pct           = :tpct,
                    trailing_trigger_price = :tt
                 WHERE trade_id = :tid AND closed_at IS NULL"
            )->execute([
                ':qn'   => $qNew,
                ':avgr' => $avgRaw,
                ':sl'   => $slFinal,
                ':tt'   => $trailingTriggerNew,
                ':tpct' => $trailingPctNew,
                ':tid'  => $tradeId,
            ]);

            // 3) trades → AVERAGED + qty_current + sl_current + trailing + break_even_price
            $pdo->prepare(
                "UPDATE trades SET
                    status              = 'AVERAGED',
                    qty_current         = :qn,
                    sl_current          = :sl,
                    trailing_pct        = :tpct,
                    trailing_trigger    = :tt,
                    break_even_price    = :pbe
                 WHERE id = :id"
            )->execute([
                ':qn'   => $qNew,
                ':sl'   => $slFinal,
                ':tt'   => $trailingTriggerNew,
                ':tpct' => $trailingPctNew,
                ':pbe'  => $pBe,
                ':id'   => $tradeId,
            ]);

            // 4) Отметить avg-ордер как filled
            $pdo->prepare(
                "UPDATE orders SET status = 'filled', filled_at = :now
                 WHERE id = :id"
            )->execute([
                ':now' => $now,
                ':id'  => (int)$ord['id'],
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'paper_averaging_filled', [
            'q1'                 => $q1,
            'p1'                 => $p1,
            'q2'                 => $q2,
            'p2'                 => $p2,
            'qty_new'            => $qNew,
            'avg_entry'          => $avgRaw,
            'break_even'         => $pBe,
            'sl_post_avg'        => $slPostAvg,
            'sl_final'           => $slFinal,
            'trailing_pct'       => $trailingPctNew,
            'trailing_trigger'   => $trailingTriggerNew,
            'deposit_anchor'     => $depositAnchor,
            'max_loss_usdt'      => $maxLossUsdt,
        ]);

        return [
            'type'       => 'avg_filled',
            'trade_id'   => $tradeId,
            'symbol'     => (string)$pos['symbol'],
            'price'      => $p2,
            'qty_added'  => $q2,
            'qty_total'  => $qNew,
            'break_even' => $pBe,
        ];
    }

    /**
     * Рассчитать реализованный PnL по позиции.
     *
     * @param array $pos    Строка из positions
     * @param float $closePrice
     * @return float
     */
    private function calcPnl(array $pos, float $closePrice): float
    {
        $isLong = ((string)$pos['side'] === 'Buy');
        $qty    = (float)$pos['qty'];
        $entry  = (float)$pos['avg_entry_price'];
        $sign   = $isLong ? 1.0 : -1.0;

        $grossPnl = $sign * ($closePrice - $entry) * $qty;

        $takerFee = (float)Config::get('taker_fee_pct', null, 0.055);
        $feeOpen  = $qty * $entry * $takerFee / 100;
        $feeClose = $qty * $closePrice * $takerFee / 100;

        return $grossPnl - $feeOpen - $feeClose;
    }

    /**
     * Закрыть paper-позицию.
     */
    private function closePaperPosition(int $posId, int $tradeId, float $closePrice, string $reason, float $pnl): void
    {
        $pdo = Database::pdo();
        $now = self::nowIso();

        // positions (единая таблица)
        $pdo->prepare(
            "UPDATE positions
             SET closed_at = :now, close_reason = :reason, realised_pnl_usdt = :pnl
             WHERE id = :id"
        )->execute([':now' => $now, ':reason' => $reason, ':pnl' => $pnl, ':id' => $posId]);

        // paper_positions (обратная совместимость)
        $pdo->prepare(
            "UPDATE paper_positions
             SET closed_at = :now, close_reason = :reason, realised_pnl_usdt = :pnl
             WHERE trade_id = :tid AND closed_at IS NULL"
        )->execute([':now' => $now, ':reason' => $reason, ':pnl' => $pnl, ':tid' => $tradeId]);

        $statusClose = $pnl >= 0 ? 'CLOSED_PROFIT' : 'CLOSED_LOSS';
        $pdo->prepare(
            "UPDATE trades SET status = :s, closed_at = :now, realized_pnl_usdt = :pnl WHERE id = :id"
        )->execute([':s' => $statusClose, ':now' => $now, ':pnl' => $pnl, ':id' => $tradeId]);

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'paper_position_closed', [
            'reason'      => $reason,
            'close_price' => $closePrice,
            'pnl'         => $pnl,
        ]);

        // Отменить дочерние pending ордера
        $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', cancelled_at = :now
             WHERE trade_id = :tid AND exchange = 'paper' AND status IN ('placed','pending')"
        )->execute([':now' => $now, ':tid' => $tradeId]);

        $pdo->prepare(
            "UPDATE paper_orders SET status = 'cancelled' WHERE trade_id = :tid AND status = 'pending'"
        )->execute([':tid' => $tradeId]);
    }

    /**
     * Проверить условие §5.5: pending-conditional прошёл расчётный SL_init в плохую сторону.
     * Вызывается из cron_minute. Отменяет conditional, если условие выполнено.
     *
     * @return array Список отменённых trade_id
     */
    public function cancelStalePendingConditionals(): array
    {
        $cancelled = [];
        $pdo       = Database::pdo();

        // v0.8.0.13: фильтр по ignore_sl_until_open убран — решение принимаем понужно:
        //   ignore_sl_until_open=0 — старая логика (отмена по пересечению SL_init)
        //   ignore_sl_until_open=1 — отменяем ТОЛЬКО при наличии парной позиции
        //   (same strategy_id + mode + symbol + opposite side, status OPEN/AVERAGED).
        $rows = $pdo->query(
            "SELECT t.id, t.symbol, t.side, t.sl_init, t.entry_ref,
                    t.ignore_sl_until_open, t.strategy_id, t.mode
             FROM trades t
             WHERE t.status = 'PENDING_CONDITIONAL'
               AND t.sl_init IS NOT NULL"
        )->fetchAll();

        $pairStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM trades
             WHERE strategy_id = :sid AND mode = :mode AND symbol = :sym
               AND side = :opp_side
               AND status IN ('OPEN','AVERAGED')"
        );

        foreach ($rows as $row) {
            $symbol   = (string)$row['symbol'];
            $side     = (string)$row['side'];  // 'long'|'short'
            $slInit   = (float)$row['sl_init'];
            $tradeId  = (int)$row['id'];
            $ignoreSl = (int)($row['ignore_sl_until_open'] ?? 0);
            $stratId  = (string)($row['strategy_id'] ?? '');
            $mode     = (string)($row['mode'] ?? '');

            $shouldCancel = false;
            $cancelReason = null;
            $currentPrice = null;

            if ($ignoreSl === 1) {
                // RECOVERED — отменяем ТОЛЬКО при наличии пары.
                $oppSide = ($side === 'long') ? 'short' : 'long';
                $pairStmt->execute([
                    ':sid'      => $stratId,
                    ':mode'     => $mode,
                    ':sym'      => $symbol,
                    ':opp_side' => $oppSide,
                ]);
                if ((int)$pairStmt->fetchColumn() > 0) {
                    $shouldCancel = true;
                    $cancelReason = 'recovered_pair';
                }
            } else {
                // Обычный conditional — отменяем при пересечении SL.
                $currentPrice = $this->getCurrentPrice($symbol);
                if ($currentPrice === null) {
                    continue;
                }
                if ($side === 'long' && $currentPrice < $slInit) {
                    $shouldCancel = true; $cancelReason = 'sl_passed';
                } elseif ($side === 'short' && $currentPrice > $slInit) {
                    $shouldCancel = true; $cancelReason = 'sl_passed';
                }
            }

            if ($shouldCancel) {
                $pdo->prepare(
                    "UPDATE trades SET status = 'CANCELLED' WHERE id = :id"
                )->execute([':id' => $tradeId]);

                $pdo->prepare(
                    "UPDATE orders SET status = 'cancelled', cancelled_at = :now
                     WHERE trade_id = :tid AND exchange = 'paper' AND status IN ('placed','pending')"
                )->execute([':tid' => $tradeId, ':now' => self::nowIso()]);

                $pdo->prepare(
                    "UPDATE paper_orders SET status = 'cancelled' WHERE trade_id = :tid AND status = 'pending'"
                )->execute([':tid' => $tradeId]);

                $cancelled[] = $tradeId;

                $eventKind = ($cancelReason === 'recovered_pair')
                    ? 'conditional_cancelled_recovered_pair'
                    : 'conditional_cancelled_sl_passed';
                EventRecorder::tradeEvent($tradeId, EventRecorder::WARN, $eventKind, [
                    'symbol'        => $symbol,
                    'side'          => $side,
                    'current_price' => $currentPrice,
                    'sl_init'       => $slInit,
                    'reason'        => $cancelReason,
                ]);
            }
        }

        return $cancelled;
    }

    private static function nowIso(): string
    {
        $t     = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\\TH:i:s.', (int)$t) . $micro . 'Z';
    }

    // ──────────────────────────────────────────────────────────────────────
    // v0.7.3: ручное закрытие активных позиций и отмена PENDING_CONDITIONAL.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * v0.7.3: ручное закрытие открытой / усреднённой paper-позиции.
     *
     * Берёт текущую цену, считает PnL через calcPnl(), вызывает closePaperPosition()
     * с reason='manual'. Статус trade будет CLOSED_PROFIT / CLOSED_LOSS (по знаку PnL).
     */
    public function closePositionManual(int $tradeId): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "SELECT * FROM positions WHERE trade_id = :tid AND closed_at IS NULL LIMIT 1"
        );
        $stmt->execute([':tid' => $tradeId]);
        $pos = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($pos === false) {
            return ['ok' => false, 'error' => 'no_open_position'];
        }

        $symbol = (string)$pos['symbol'];
        $price  = $this->getCurrentPrice($symbol);
        if ($price === null) {
            return ['ok' => false, 'error' => 'no_price', 'symbol' => $symbol];
        }

        $pnl = $this->calcPnl($pos, $price);
        $this->closePaperPosition((int)$pos['id'], $tradeId, $price, 'manual', $pnl);

        return [
            'ok'          => true,
            'close_price' => $price,
            'pnl'         => $pnl,
            'reason'      => 'manual',
        ];
    }

    /**
     * v0.7.3: ручная отмена PENDING_CONDITIONAL.
     *
     * Отменяет входной ордер и выставляет trade.status = CANCELLED.
     */
    public function cancelPendingManual(int $tradeId): array
    {
        $pdo = Database::pdo();
        $now = self::nowIso();

        $pdo->prepare(
            "UPDATE trades SET status = 'CANCELLED' WHERE id = :id"
        )->execute([':id' => $tradeId]);

        $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', cancelled_at = :now
             WHERE trade_id = :tid AND exchange = 'paper' AND status IN ('placed','pending')"
        )->execute([':tid' => $tradeId, ':now' => $now]);

        $pdo->prepare(
            "UPDATE paper_orders SET status = 'cancelled' WHERE trade_id = :tid AND status = 'pending'"
        )->execute([':tid' => $tradeId]);

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'manual_cancel_pending', []);

        return ['ok' => true, 'reason' => 'manual_cancel'];
    }
}
