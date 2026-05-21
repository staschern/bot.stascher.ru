<?php
declare(strict_types=1);

namespace BybitBot\Exchange;

use BybitBot\Bybit\Client;
use BybitBot\Bybit\Errors;
use BybitBot\Bybit\Signer;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;

/**
 * BybitAdapter — реальное взаимодействие с Bybit (testnet или live).
 *
 * Реализует ExchangeAdapter. Все торговые операции отправляются на биржу через
 * Bybit\Client (REST V5 HMAC). Локально хранит зеркало состояния в таблицах
 * orders/positions с exchange = 'testnet'|'live'.
 *
 * Принцип работы:
 *  - placeConditional() создаёт запись в orders (status='pending'), отправляет
 *    ордер на Bybit, при успехе — status='placed', сохраняет bybit_order_id.
 *  - tick() — полл состояния и сверка с БД (для cron_minute).
 *  - cancelOrder(), setTradingStop(), closePosition() — прямые вызовы API.
 *
 * См. spec.md §11 и §13a (v0.5.0).
 */
final class BybitAdapter implements ExchangeAdapter
{
    /**
     * v0.8.0.14: кэш position mode по символу. true = hedge (использовать
     * positionIdx 1/2), false = one-way (positionIdx 0). По умолчанию one-way.
     * Заполняется по результатам размещения: если получили
     * 'position idx not match position mode' — переключаем и повторяем.
     *
     * v0.9.0: кэш стал two-level: [accountKey][symbol] => bool.
     * accountKey = 'acc:{id}' для multi-account, '«legacy:{exchange}»' для старых вызовов
     * без accountId. Разные аккаунты могут быть в разных position-mode на одном
     * и том же символе — путать нельзя.
     * @var array<string,array<string,bool>>
     */
    private static $hedgeModeCache = [];

    /** @var Client */
    private $client;

    /** @var string 'testnet'|'live' */
    private $exchange;

    /**
     * v0.9.0: id в bybit_accounts — если адаптер создан через AdapterFactory::forAccount().
     * NULL — legacy-вызов (ключи берутся из $_ENV / live_keys.php). После
     * полного перехода (шаг 4 релиза) NULL будет возможен только в dev-скриптах.
     * @var int|null
     */
    private $accountId;

    /**
     * Снимок имени аккаунта (для логов). Доступен только при создании через forAccount().
     * @var string|null
     */
    private $accountName;

    /**
     * v0.9.0: конструктор расширен опциональным $accountId.
     *
     * Старый вызов `new BybitAdapter('live')` работает как раньше — ключи из $_ENV
     * (в этом случае $_ENV заполняется из data/secrets/live_keys.php в Bootstrap).
     *
     * Новый вызов `new BybitAdapter('live', $accountId)` читает ключи из файла
     * data/secrets/account_{id}.php (через SecretsService::readAccountKeys).
     * Если файл отсутствует — падаем с RuntimeException (без тихого fallback на ENV,
     * иначе можно случайно сходить в чужой аккаунт).
     */
    public function __construct(string $exchange, ?int $accountId = null)
    {
        if ($exchange !== 'testnet' && $exchange !== 'live') {
            throw new \InvalidArgumentException("BybitAdapter: exchange должен быть 'testnet' или 'live', получено: {$exchange}");
        }
        $this->exchange  = $exchange;
        $this->accountId = $accountId;

        if ($accountId !== null) {
            // Подгружаем имя из bybit_accounts (снимок, для логов).
            $acc = \BybitBot\Core\BybitAccountsRepo::find($accountId);
            if ($acc === null) {
                throw new \RuntimeException("BybitAdapter: аккаунт #{$accountId} не найден в bybit_accounts");
            }
            if ($acc['network'] !== $exchange) {
                throw new \RuntimeException(
                    "BybitAdapter: network расхождение для account #{$accountId}: "
                    . "ожидали {$exchange}, в БД {$acc['network']}"
                );
            }
            $this->accountName = (string)$acc['name'];
        }

        $this->client = $this->buildClient($exchange);
    }

    /** v0.9.0: id аккаунта, к которому привязан адаптер (NULL для legacy-вызовов). */
    public function accountId(): ?int
    {
        return $this->accountId;
    }

    /** v0.9.0: имя аккаунта (NULL для legacy). */
    public function accountName(): ?string
    {
        return $this->accountName;
    }

    /**
     * v0.9.0: ключ для $hedgeModeCache. Разделяет multi-account от legacy-вызовов.
     */
    private function accountCacheKey(): string
    {
        return $this->accountId !== null ? ('acc:' . $this->accountId) : ('legacy:' . $this->exchange);
    }

    private function buildClient(string $exchange): Client
    {
        $isLive = ($exchange === 'live');

        $mainnetUrl = (string)Config::bootstrap('bybit.base_url_mainnet');
        $testnetUrl = (string)Config::bootstrap('bybit.base_url_testnet');

        // Public эндпоинты всегда с mainnet (для корректных kline/tickers).
        $baseUrlPublic = $mainnetUrl;

        if ($isLive) {
            $baseUrlPrivate = $mainnetUrl;
            $network        = 'mainnet';
        } else {
            $baseUrlPrivate = $testnetUrl;
            $network        = 'testnet';
        }

        // v0.9.0: если адаптер привязан к конкретному account_id — берём ключи из файла
        // data/secrets/account_{id}.php. Иначе (legacy) — из $_ENV (куда их положил
        // Bootstrap из live_keys.php). После полного перехода ветка legacy уйдёт.
        if ($this->accountId !== null) {
            $keys = \BybitBot\Bybit\SecretsService::readAccountKeys($this->accountId);
            if ($keys === null) {
                throw new \RuntimeException(
                    "BybitAdapter: отсутствуют ключи в файле account_" . $this->accountId . '.php'
                    . ' — сохраните их через UI «Аккаунты Bybit»'
                );
            }
            $apiKey    = $keys['api_key'];
            $apiSecret = $keys['api_secret'];
        } elseif ($isLive) {
            $apiKey    = (string)($_ENV['BYBIT_API_KEY_MAINNET'] ?? '');
            $apiSecret = (string)($_ENV['BYBIT_API_SECRET_MAINNET'] ?? '');
        } else {
            $apiKey    = (string)($_ENV['BYBIT_API_KEY_TESTNET'] ?? '');
            $apiSecret = (string)($_ENV['BYBIT_API_SECRET_TESTNET'] ?? '');
        }

        $recvWindow = (int)Config::bootstrap('bybit.recv_window', 5000);
        $debugLog   = filter_var($_ENV['BYBIT_DEBUG_LOG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        $signer = ($apiKey !== '' && $apiSecret !== '')
            ? new Signer($apiKey, $apiSecret, $recvWindow)
            : null;

        return new Client($baseUrlPublic, $baseUrlPrivate, $signer, $network, $debugLog);
    }

    // ── Справочники и состояние ──────────────────────────────────

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
            Logger::get()->warning('bybit_adapter: getKline failed', [
                'symbol' => $symbol, 'error' => $resp['ret_msg'] ?? 'unknown',
            ]);
            return [];
        }

        $out = [];
        foreach ($resp['result']['list'] as $candle) {
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
        Logger::get()->warning('bybit_adapter: getWalletBalance failed', [
            'exchange' => $this->exchange,
            'error'    => $resp['ret_msg'] ?? 'unknown',
        ]);
        return ['totalWalletBalance' => 0.0, 'availableBalance' => 0.0];
    }

    /**
     * Получить открытые позиции с биржи.
     *
     * @return array<int, array>
     */
    public function getPositions(?string $symbol = null): array
    {
        // Client::getPositions(symbol) — принимает nullable symbol
        $resp = $this->client->getPositions($symbol);
        if ($resp['category'] !== Errors::SUCCESS) {
            Logger::get()->warning('bybit_adapter: getPositions failed', [
                'exchange' => $this->exchange,
                'error'    => $resp['ret_msg'] ?? 'unknown',
            ]);
            return [];
        }
        return $resp['result']['list'] ?? [];
    }

    /**
     * Получить открытые ордера с биржи.
     *
     * @return array<int, array>
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        $resp = $this->client->getOpenOrders($symbol);
        if ($resp['category'] !== Errors::SUCCESS) {
            Logger::get()->warning('bybit_adapter: getOpenOrders failed', [
                'exchange' => $this->exchange,
                'error'    => $resp['ret_msg'] ?? 'unknown',
            ]);
            return [];
        }
        return $resp['result']['list'] ?? [];
    }

    /**
     * @return array<int, array>
     */
    public function getExecutions(string $orderLinkIdOrSymbol, int $sinceUnixMs): array
    {
        // Client::getExecutions(symbol, orderLinkId) — orderLinkId optional
        $resp = $this->client->getExecutions($orderLinkIdOrSymbol);
        if ($resp['category'] !== Errors::SUCCESS) {
            return [];
        }
        return $resp['result']['list'] ?? [];
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

    // ── Торговые операции ────────────────────────────────────────

    /**
     * Разместить conditional ордер на Bybit.
     *
     * @param array $params {
     *   trade_id, symbol, side, qty, trigger_price, tp_price, sl_price,
     *   purpose, order_link_id, leverage, margin_mode
     * }
     * @return string Bybit order_id (или локальный при ошибке)
     */
    public function placeConditional(array $params): string
    {
        $pdo = Database::pdo();
        $now = self::nowIso();

        $tradeId     = (int)$params['trade_id'];
        $symbol      = (string)($params['symbol'] ?? '');
        $side        = (string)($params['side'] ?? 'Buy');
        $qty         = (float)($params['qty'] ?? 0);
        $triggerPx   = (float)($params['trigger_price'] ?? 0);
        $slPrice     = isset($params['sl_price']) ? (float)$params['sl_price'] : null;
        $tpPrice     = isset($params['tp_price']) ? (float)$params['tp_price'] : null;
        $purpose     = (string)($params['purpose'] ?? 'entry_conditional');
        $linkId      = (string)($params['order_link_id'] ?? ('bb-' . $this->exchange . '-' . $tradeId . '-' . bin2hex(random_bytes(4))));

        // 1. Создать запись в orders (status='pending')
        // v0.9.0-step4a.2: всегда проставляем account_id, если адаптер с ним создан.
        $stmt = $pdo->prepare(
            'INSERT INTO orders
             (trade_id, purpose, side, order_type, qty, trigger_price, sl_price,
              reduce_only, bybit_order_link_id, status, placed_at, paper, exchange,
              account_id)
             VALUES (:tid, :purpose, :side, :otype, :qty, :tprice, :slp,
                     :ro, :linkid, :status, :pat, :paper, :exch, :acc)'
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
            ':status'  => 'pending',
            ':pat'     => $now,
            ':paper'   => 0,
            ':exch'    => $this->exchange,
            ':acc'     => $this->accountId,
        ]);
        $localOrderId = (int)$pdo->lastInsertId();

        // 2. Установить плечо перед размещением
        if (isset($params['leverage']) && (int)$params['leverage'] > 0) {
            $this->setLeverage($symbol, (int)$params['leverage']);
        }

        // 3. Вычислить triggerDirection (обязательное поле V5 для conditional)
        // Bybit V5: 1 = Rise (last price «поднимется» до trigger), 2 = Fall (last price «опустится»).
        // Не зависит от long/short — зависит от того, выше или ниже trigger расположена текущая цена.
        $triggerDirection = isset($params['trigger_direction']) ? (int)$params['trigger_direction'] : 0;
        if ($triggerDirection !== 1 && $triggerDirection !== 2) {
            $triggerDirection = $this->resolveTriggerDirection($symbol, $triggerPx);
        }

        // 4. Отправить ордер на Bybit
        // v0.8.0.14: positionIdx из кэша режима (one-way=0, hedge: Buy=1/Sell=2).
        $accKey      = $this->accountCacheKey();
        $hedge       = self::$hedgeModeCache[$accKey][$symbol] ?? false;
        $positionIdx = self::resolvePositionIdx($side, $hedge);

        $orderParams = [
            'category'        => 'linear',
            'symbol'          => $symbol,
            'side'            => $side,
            'orderType'       => 'Market',
            'qty'             => (string)$qty,
            'triggerPrice'    => (string)$triggerPx,
            'triggerDirection'=> $triggerDirection,
            'triggerBy'       => 'LastPrice',
            'timeInForce'     => 'GTC',
            'orderLinkId'     => $linkId,
            'reduceOnly'      => false,
            'closeOnTrigger'  => false,
            'positionIdx'     => $positionIdx,
        ];

        if ($tpPrice !== null && $tpPrice > 0) {
            $orderParams['takeProfit'] = (string)$tpPrice;
        }
        if ($slPrice !== null && $slPrice > 0) {
            $orderParams['stopLoss'] = (string)$slPrice;
        }

        $resp = $this->client->placeOrder($orderParams);

        // v0.8.0.14: авто-ретрай на position idx mismatch (ровно 1 раз).
        // Bybit V5 retCode 10001 с текстом 'position idx not match position mode'.
        if (!(($resp['category'] ?? null) === Errors::SUCCESS && isset($resp['result']['orderId']))
            && self::isPositionIdxMismatch($resp)
        ) {
            $newHedge    = !$hedge;
            $newPosIdx   = self::resolvePositionIdx($side, $newHedge);
            $orderParams['positionIdx'] = $newPosIdx;

            EventRecorder::event(EventRecorder::WARN, 'position_mode_retry', $symbol, [
                'exchange'      => $this->exchange,
                'trade_id'      => $tradeId,
                'old_hedge'     => $hedge,
                'new_hedge'     => $newHedge,
                'old_positionIdx' => $positionIdx,
                'new_positionIdx' => $newPosIdx,
                'side'          => $side,
            ]);
            Logger::get()->warning('bybit_adapter: position idx mismatch — ретрай с противоположным режимом', [
                'symbol'        => $symbol,
                'old_hedge'     => $hedge,
                'new_hedge'     => $newHedge,
                'new_positionIdx' => $newPosIdx,
            ]);

            $resp = $this->client->placeOrder($orderParams);

            // Если вторая попытка успешна — фиксируем режим в кэше.
            if (($resp['category'] ?? null) === Errors::SUCCESS && isset($resp['result']['orderId'])) {
                if (!isset(self::$hedgeModeCache[$accKey])) self::$hedgeModeCache[$accKey] = [];
                self::$hedgeModeCache[$accKey][$symbol] = $newHedge;
                EventRecorder::event(EventRecorder::INFO, 'position_mode_resolved', $symbol, [
                    'exchange' => $this->exchange,
                    'hedge'    => $newHedge,
                ]);
            }
        }

        if ($resp['category'] === Errors::SUCCESS && isset($resp['result']['orderId'])) {
            $bybitOrderId = (string)$resp['result']['orderId'];
            $pdo->prepare(
                "UPDATE orders
                 SET status = 'placed', bybit_order_id = :bid, raw_response_json = :raw
                 WHERE id = :id"
            )->execute([
                ':bid' => $bybitOrderId,
                ':raw' => json_encode($resp['result'], JSON_UNESCAPED_UNICODE),
                ':id'  => $localOrderId,
            ]);

            EventRecorder::event(EventRecorder::INFO, 'bybit_conditional_placed', $symbol, [
                'exchange'      => $this->exchange,
                'trade_id'      => $tradeId,
                'bybit_order_id'=> $bybitOrderId,
                'side'          => $side,
                'trigger_price' => $triggerPx,
                'qty'           => $qty,
                'sl'            => $slPrice,
                'tp'            => $tpPrice,
            ]);

            Logger::get()->info('bybit_adapter: conditional placed', [
                'exchange'       => $this->exchange,
                'trade_id'       => $tradeId,
                'bybit_order_id' => $bybitOrderId,
                'symbol'         => $symbol,
                'trigger'        => $triggerPx,
            ]);

            return $bybitOrderId;
        }

        // Ошибка Bybit — пометить как rejected
        $errMsg = $resp['ret_msg'] ?? ($resp['error'] ?? 'unknown');
        $pdo->prepare(
            "UPDATE orders SET status = 'rejected', raw_response_json = :raw WHERE id = :id"
        )->execute([
            ':raw' => json_encode($resp, JSON_UNESCAPED_UNICODE),
            ':id'  => $localOrderId,
        ]);

        EventRecorder::event(EventRecorder::ERROR, 'bybit_conditional_rejected', $symbol, [
            'exchange'  => $this->exchange,
            'trade_id'  => $tradeId,
            'error'     => $errMsg,
            'ret_code'  => $resp['ret_code'] ?? null,
        ]);

        throw new \RuntimeException("BybitAdapter: placeConditional rejected — {$errMsg}");
    }

    /**
     * Отменить ордер на Bybit.
     *
     * @param string $orderIdOrLink Bybit order_id или orderLinkId
     * @return bool
     */
    public function cancelOrder(string $orderIdOrLink): bool
    {
        $pdo = Database::pdo();
        $now = self::nowIso();

        // Сначала ищем локально по link_id
        $stmt = $pdo->prepare(
            "SELECT id, bybit_order_id, trade_id
             FROM orders WHERE bybit_order_link_id = :lid AND exchange = :exch
               AND status IN ('placed', 'pending') LIMIT 1"
        );
        $stmt->execute([':lid' => $orderIdOrLink, ':exch' => $this->exchange]);
        $row = $stmt->fetch();

        $bybitId = null;
        if ($row !== false) {
            $bybitId = $row['bybit_order_id'];
        }

        // Если не нашли по link — пробуем по bybit_order_id
        if ($bybitId === null) {
            $stmt2 = $pdo->prepare(
                "SELECT id, bybit_order_id, trade_id
                 FROM orders WHERE bybit_order_id = :bid AND exchange = :exch
                   AND status IN ('placed', 'pending') LIMIT 1"
            );
            $stmt2->execute([':bid' => $orderIdOrLink, ':exch' => $this->exchange]);
            $row = $stmt2->fetch();
            if ($row !== false) {
                $bybitId = $row['bybit_order_id'];
            }
        }

        // Вызов Bybit API
        $cancelOk = false;
        if ($bybitId !== null || $orderIdOrLink !== '') {
            $symbol = $this->getSymbolByOrderId($bybitId ?? $orderIdOrLink);
            // Client::cancelOrder(symbol, orderLinkId)
            $linkIdForCancel = ($bybitId === null) ? $orderIdOrLink : $orderIdOrLink;
            $resp = $this->client->cancelOrder($symbol, $linkIdForCancel);
            $cancelOk = ($resp['category'] === Errors::SUCCESS);
            if (!$cancelOk) {
                Logger::get()->warning('bybit_adapter: cancelOrder failed', [
                    'exchange' => $this->exchange,
                    'id'       => $orderIdOrLink,
                    'error'    => $resp['ret_msg'] ?? 'unknown',
                ]);
            }
        }

        // Обновить локально в любом случае (если был на бирже — теперь нет)
        $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', cancelled_at = :now
             WHERE (bybit_order_link_id = :lid OR bybit_order_id = :oid)
               AND exchange = :exch AND status IN ('placed', 'pending')"
        )->execute([
            ':now'  => $now,
            ':lid'  => $orderIdOrLink,
            ':oid'  => $orderIdOrLink,
            ':exch' => $this->exchange,
        ]);

        return $cancelOk;
    }

    /**
     * Изменить ордер.
     */
    public function amendOrder(string $orderId, array $fields): bool
    {
        $resp = $this->client->amendOrder(array_merge($fields, [
            'category' => 'linear',
            'orderId'  => $orderId,
        ]));
        return ($resp['category'] === Errors::SUCCESS);
    }

    /**
     * Установить SL/TP на позицию через setTradingStop.
     *
     * v0.8.0.11: серверный трейлинг Bybit (trailingStop/activePrice) НЕ используется,
     * потому что Bybit V5 API принимает trailingStop как АБСОЛЮТНОЕ расстояние в quote
     * валюте, а у нас стратегии задают трейлинг в ПРОЦЕНТАХ. Поэтому мы трейлим сами
     * в LiveReconciler::reconcilePositions, обновляя stopLoss по setTradingStop при
     * каждом тике (симметрично PaperAdapter::tickPositions).
     *
     * Принимаемые поля:
     *   sl_price          — установить stopLoss
     *   tp_price          — установить takeProfit
     *   clear_tp          — снять статичный TP (takeProfit="0")
     *   clear_trailing    — снять серверный трейлинг (trailingStop="0", activePrice="0")
     *                       используется одноразовым скриптом сброса
     *   trade_id          — (v0.8.0.15, опционально) id сделки для записи trade_event
     *   context           — (v0.8.0.15, опционально) свободный контекст (purpose, source)
     */
    public function setTradingStop(string $symbol, string $side, array $fields): bool
    {
        // v0.8.0.14: positionIdx выбирается из кэша режима для символа.
        // hedge → long:1, short:2. one-way → 0.
        $accKey      = $this->accountCacheKey();
        $hedge       = self::$hedgeModeCache[$accKey][$symbol] ?? false;
        $isLong      = (strtolower($side) === 'buy' || strtolower($side) === 'long');
        $positionIdx = $hedge ? ($isLong ? 1 : 2) : 0;
        $params = [
            'category'    => 'linear',
            'symbol'      => $symbol,
            'positionIdx' => $positionIdx,
        ];
        if (isset($fields['sl_price']) && (float)$fields['sl_price'] > 0) {
            $params['stopLoss'] = (string)$fields['sl_price'];
        }
        if (isset($fields['tp_price']) && (float)$fields['tp_price'] > 0) {
            $params['takeProfit'] = (string)$fields['tp_price'];
        } elseif (!empty($fields['clear_tp'])) {
            // v0.8.0.7: явно снять статичный TP на бирже (стратегии работают с трейлингом).
            $params['takeProfit'] = '0';
        }
        if (!empty($fields['clear_trailing'])) {
            // v0.8.0.11: снять серверный трейлинг (трейлим сами в реконсайлере).
            $params['trailingStop'] = '0';
            $params['activePrice']  = '0';
        }

        // v0.8.0.15: оборачиваем вызов и логируем результат в trade_events,
        // чтобы silent failure был виден в БД (см. трейд #63).
        $tradeId  = isset($fields['trade_id']) ? (int)$fields['trade_id'] : null;
        $ctx      = isset($fields['context']) && is_array($fields['context']) ? $fields['context'] : [];

        $resp     = null;
        $err      = null;
        try {
            $resp = $this->client->setTradingStop($params);
            $ok   = (($resp['category'] ?? null) === Errors::SUCCESS);
        } catch (\Throwable $e) {
            $ok  = false;
            $err = $e->getMessage();
            Logger::get()->error('bybit_adapter: setTradingStop exception', [
                'symbol' => $symbol, 'side' => $side, 'err' => $err,
            ]);
        }

        // v0.8.0.16: auto-retry на position idx mismatch (ret_code 10001, см. трейд #63 BUSDT).
        // Аналогично placeConditional: пробуем с противоположным режимом и обновляем кэш.
        if (!$ok && $resp !== null && self::isPositionIdxMismatch($resp)) {
            $newHedge  = !$hedge;
            $newPosIdx = self::resolvePositionIdx($side, $newHedge);
            $params['positionIdx'] = $newPosIdx;

            EventRecorder::event(EventRecorder::WARN, 'position_mode_retry', $symbol, [
                'exchange'        => $this->exchange,
                'context'         => 'setTradingStop',
                'trade_id'        => $tradeId,
                'old_hedge'       => $hedge,
                'new_hedge'       => $newHedge,
                'old_positionIdx' => $positionIdx,
                'new_positionIdx' => $newPosIdx,
                'side'            => $side,
            ]);
            Logger::get()->warning('bybit_adapter: setTradingStop position idx mismatch — ретрай', [
                'symbol'    => $symbol,
                'old_hedge' => $hedge,
                'new_hedge' => $newHedge,
            ]);

            try {
                $resp = $this->client->setTradingStop($params);
                $ok   = (($resp['category'] ?? null) === Errors::SUCCESS);
            } catch (\Throwable $e) {
                $ok  = false;
                $err = $e->getMessage();
            }

            if ($ok) {
                if (!isset(self::$hedgeModeCache[$accKey])) self::$hedgeModeCache[$accKey] = [];
                self::$hedgeModeCache[$accKey][$symbol] = $newHedge;
                EventRecorder::event(EventRecorder::INFO, 'position_mode_resolved', $symbol, [
                    'exchange' => $this->exchange,
                    'context'  => 'setTradingStop',
                    'hedge'    => $newHedge,
                ]);
                // Обновляем локальные переменные для лог-payload ниже
                $hedge       = $newHedge;
                $positionIdx = $newPosIdx;
            }
        }

        $logPayload = [
            'symbol'       => $symbol,
            'side'         => $side,
            'position_idx' => $positionIdx,
            'hedge_mode'   => $hedge,
            'request'      => $params,
            'response'     => $resp,
            'exception'    => $err,
            'context'      => $ctx,
        ];

        if ($tradeId !== null && $tradeId > 0) {
            if ($ok) {
                EventRecorder::tradeEvent($tradeId, EventRecorder::INFO,
                    'bybit_trading_stop_set', $logPayload);
            } else {
                EventRecorder::tradeEvent($tradeId, EventRecorder::ERROR,
                    'bybit_trading_stop_failed', $logPayload);
            }
        } else {
            // Без trade_id всё-равно пишем global-event, чтобы не терять следы
            EventRecorder::event($ok ? EventRecorder::INFO : EventRecorder::ERROR,
                $ok ? 'bybit_trading_stop_set' : 'bybit_trading_stop_failed',
                $symbol, $logPayload);
        }

        if (!$ok) {
            Logger::get()->warning('bybit_adapter: setTradingStop failed', [
                'symbol'   => $symbol,
                'side'     => $side,
                'ret_code' => $resp['ret_code'] ?? null,
                'ret_msg'  => $resp['ret_msg']  ?? null,
            ]);
        }

        return $ok;
    }

    /**
     * Установить плечо.
     */
    public function setLeverage(string $symbol, int $value): bool
    {
        $resp = $this->client->setLeverage($symbol, $value);
        return ($resp['category'] === Errors::SUCCESS || ($resp['ret_code'] ?? 0) === 110043);
        // 110043 = leverage not changed
    }

    /**
     * Переключить margin mode.
     */
    public function switchMarginMode(string $crossOrIsolated): bool
    {
        // Используем пустой символ — применится к аккаунту целиком (cross mode)
        $resp = $this->client->switchMarginMode('', $crossOrIsolated, 1);
        return ($resp['category'] === Errors::SUCCESS);
    }

    /**
     * Закрыть позицию market-ордером (reduce-only).
     */
    public function closePosition(int $tradeId, string $reason): void
    {
        $pdo = Database::pdo();
        $now = self::nowIso();

        // Найти позицию
        $stmt = $pdo->prepare(
            "SELECT p.*, t.symbol as trade_symbol
             FROM positions p
             JOIN trades t ON t.id = p.trade_id
             WHERE p.trade_id = :tid AND p.exchange = :exch AND p.closed_at IS NULL"
        );
        $stmt->execute([':tid' => $tradeId, ':exch' => $this->exchange]);
        $pos = $stmt->fetch();

        if ($pos === false) {
            Logger::get()->warning('bybit_adapter: closePosition — позиция не найдена', [
                'trade_id' => $tradeId, 'exchange' => $this->exchange,
            ]);
            return;
        }

        $symbol = (string)($pos['symbol'] ?? $pos['trade_symbol']);
        $side   = (string)$pos['side'];
        $qty    = (float)$pos['qty'];

        // Для закрытия — противоположная сторона
        $closeSide = ($side === 'Buy') ? 'Sell' : 'Buy';

        $linkId = 'close-' . $tradeId . '-' . bin2hex(random_bytes(4));

        // v0.8.0.14: positionIdx из кэша. Для reduce-only принадлежность
        // к позиции определяется исходным $side (буквальным для
        // позиции), а не closeSide.
        $accKey         = $this->accountCacheKey();
        $hedge          = self::$hedgeModeCache[$accKey][$symbol] ?? false;
        $closePosIdx    = $hedge ? ((strtolower($side) === 'buy') ? 1 : 2) : 0;

        $orderParams = [
            'category'       => 'linear',
            'symbol'         => $symbol,
            'side'           => $closeSide,
            'orderType'      => 'Market',
            'qty'            => (string)$qty,
            'timeInForce'    => 'GTC',
            'orderLinkId'    => $linkId,
            'reduceOnly'     => true,
            'closeOnTrigger' => true,
            'positionIdx'    => $closePosIdx,
        ];
        $resp = $this->client->placeOrder($orderParams);

        // v0.8.0.16: auto-retry на position idx mismatch.
        if (!(($resp['category'] ?? null) === Errors::SUCCESS) && self::isPositionIdxMismatch($resp)) {
            $newHedge       = !$hedge;
            $newClosePosIdx = $newHedge ? ((strtolower($side) === 'buy') ? 1 : 2) : 0;
            $orderParams['positionIdx'] = $newClosePosIdx;

            EventRecorder::event(EventRecorder::WARN, 'position_mode_retry', $symbol, [
                'exchange'        => $this->exchange,
                'context'         => 'closePosition',
                'trade_id'        => $tradeId,
                'old_hedge'       => $hedge,
                'new_hedge'       => $newHedge,
                'old_positionIdx' => $closePosIdx,
                'new_positionIdx' => $newClosePosIdx,
                'side'            => $side,
            ]);
            Logger::get()->warning('bybit_adapter: closePosition position idx mismatch — ретрай', [
                'symbol' => $symbol, 'new_hedge' => $newHedge,
            ]);

            $resp = $this->client->placeOrder($orderParams);
            if (($resp['category'] ?? null) === Errors::SUCCESS) {
                if (!isset(self::$hedgeModeCache[$accKey])) self::$hedgeModeCache[$accKey] = [];
                self::$hedgeModeCache[$accKey][$symbol] = $newHedge;
                EventRecorder::event(EventRecorder::INFO, 'position_mode_resolved', $symbol, [
                    'exchange' => $this->exchange,
                    'context'  => 'closePosition',
                    'hedge'    => $newHedge,
                ]);
            }
        }

        if ($resp['category'] === Errors::SUCCESS) {
            $pdo->prepare(
                "UPDATE positions SET closed_at = :now, close_reason = :r WHERE trade_id = :tid AND exchange = :exch"
            )->execute([':now' => $now, ':r' => $reason, ':tid' => $tradeId, ':exch' => $this->exchange]);

            $pdo->prepare(
                "UPDATE trades SET status = 'CLOSED_LOSS', closed_at = :now WHERE id = :id"
            )->execute([':now' => $now, ':id' => $tradeId]);

            EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'position_closed', [
                'exchange' => $this->exchange,
                'reason'   => $reason,
                'symbol'   => $symbol,
            ]);
        } else {
            Logger::get()->error('bybit_adapter: closePosition failed', [
                'trade_id' => $tradeId,
                'error'    => $resp['ret_msg'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Reduce-only лимит-ордер (для S2 60%-TP).
     *
     * @return string Bybit order_id
     */
    public function placeReduceOnlyLimit(
        string $symbol,
        string $side,
        float $qty,
        float $price,
        string $orderLinkId
    ): string {
        // v0.8.0.14: для reduce-only $side — сторона ордера (противоположная позиции).
        // Сама позиция в hedge: если закрываем long (ордер Sell) → positionIdx=1;
        // если закрываем short (ордер Buy) → positionIdx=2.
        $accKey      = $this->accountCacheKey();
        $hedge       = self::$hedgeModeCache[$accKey][$symbol] ?? false;
        $positionIdx = $hedge ? ((strtolower($side) === 'sell') ? 1 : 2) : 0;

        $orderParams = [
            'category'    => 'linear',
            'symbol'      => $symbol,
            'side'        => $side,
            'orderType'   => 'Limit',
            'qty'         => (string)$qty,
            'price'       => (string)$price,
            'timeInForce' => 'GTC',
            'orderLinkId' => $orderLinkId,
            'reduceOnly'  => true,
            'positionIdx' => $positionIdx,
        ];
        $resp = $this->client->placeOrder($orderParams);

        // v0.8.0.16: auto-retry на position idx mismatch.
        if (!(($resp['category'] ?? null) === Errors::SUCCESS) && self::isPositionIdxMismatch($resp)) {
            $newHedge   = !$hedge;
            $newPosIdx  = $newHedge ? ((strtolower($side) === 'sell') ? 1 : 2) : 0;
            $orderParams['positionIdx'] = $newPosIdx;

            EventRecorder::event(EventRecorder::WARN, 'position_mode_retry', $symbol, [
                'exchange'        => $this->exchange,
                'context'         => 'placeReduceOnlyLimit',
                'old_hedge'       => $hedge,
                'new_hedge'       => $newHedge,
                'old_positionIdx' => $positionIdx,
                'new_positionIdx' => $newPosIdx,
                'side'            => $side,
            ]);
            Logger::get()->warning('bybit_adapter: placeReduceOnlyLimit position idx mismatch — ретрай', [
                'symbol' => $symbol, 'new_hedge' => $newHedge,
            ]);

            $resp = $this->client->placeOrder($orderParams);
            if (($resp['category'] ?? null) === Errors::SUCCESS) {
                if (!isset(self::$hedgeModeCache[$accKey])) self::$hedgeModeCache[$accKey] = [];
                self::$hedgeModeCache[$accKey][$symbol] = $newHedge;
                EventRecorder::event(EventRecorder::INFO, 'position_mode_resolved', $symbol, [
                    'exchange' => $this->exchange,
                    'context'  => 'placeReduceOnlyLimit',
                    'hedge'    => $newHedge,
                ]);
            }
        }

        if ($resp['category'] !== Errors::SUCCESS) {
            throw new \RuntimeException("BybitAdapter: placeReduceOnlyLimit failed — " . ($resp['ret_msg'] ?? 'unknown'));
        }

        return (string)$resp['result']['orderId'];
    }

    // ── Tick / Reconciliation ──────────────────────────────────

    /**
     * Tick для testnet/live — полл состояния и обновление локальной БД.
     *
     * @return array<int, array> Список событий (пустой для реальных бирж — они обновляются через reconcile)
     */
    public function tick(): array
    {
        // v0.8.0.6: полный реконсайл через LiveReconciler:
        //  — orders: обнаружение filled (entry/avg), отмена фантомов;
        //  — positions: обновление last_price/qty, закрытие исчезнувших;
        //  — pending: обновление last_seen_price (UI бары).
        // v0.9.0-step4: передаём accountId — реконсайл фильтрует по account_id
        // во всех SQL-запросах, чтобы не трогать ордера других аккаунтов.
        $reconciler = new LiveReconciler($this->client, $this->exchange, $this, $this->accountId);
        $reconciler->reconcileAll();

        // Старая логика reconcileOrders() оставлена как public-метод для обратной
        // совместимости (может вызываться из CLI или тестов).
        return [];
    }

    /**
     * Сверка локальных placed-ордеров с реальными на бирже.
     */
    public function reconcileOrders(): void
    {
        $pdo = Database::pdo();

        // Загрузим локальные placed-ордера
        $stmt = $pdo->prepare(
            "SELECT DISTINCT t.symbol FROM orders o
             JOIN trades t ON t.id = o.trade_id
             WHERE o.exchange = :exch AND o.status = 'placed'"
        );
        $stmt->execute([':exch' => $this->exchange]);
        $symbols = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $remoteOrders = $this->getOpenOrders();
        $remoteByLinkId = [];
        foreach ($remoteOrders as $ro) {
            $lid = (string)($ro['orderLinkId'] ?? '');
            if ($lid !== '') {
                $remoteByLinkId[$lid] = $ro;
            }
        }

        // Проверить локальные placed-ордера
        $localStmt = $pdo->prepare(
            "SELECT o.id, o.bybit_order_link_id, o.bybit_order_id, o.trade_id
             FROM orders o
             WHERE o.exchange = :exch AND o.status = 'placed'"
        );
        $localStmt->execute([':exch' => $this->exchange]);
        $localOrders = $localStmt->fetchAll();

        $now = self::nowIso();
        foreach ($localOrders as $lo) {
            $lid = (string)($lo['bybit_order_link_id'] ?? '');
            if ($lid !== '' && !isset($remoteByLinkId[$lid])) {
                // Ордер у нас есть, но на бирже нет — вероятно исполнился или отменён
                $pdo->prepare(
                    "UPDATE orders SET status = 'cancelled', cancelled_at = :now WHERE id = :id"
                )->execute([':now' => $now, ':id' => (int)$lo['id']]);

                EventRecorder::tradeEvent(
                    (int)$lo['trade_id'],
                    EventRecorder::WARN,
                    'reconcile_missing_remote_order',
                    ['order_link_id' => $lid, 'exchange' => $this->exchange]
                );
            }
        }
    }

    // ── Вспомогательные ─────────────────────────────────────────

    private function getSymbolByOrderId(string $orderId): string
    {
        $stmt = Database::pdo()->prepare(
            "SELECT t.symbol FROM orders o
             JOIN trades t ON t.id = o.trade_id
             WHERE (o.bybit_order_id = :oid OR o.bybit_order_link_id = :lid)
               AND o.exchange = :exch LIMIT 1"
        );
        $stmt->execute([':oid' => $orderId, ':lid' => $orderId, ':exch' => $this->exchange]);
        $row = $stmt->fetch();
        return $row !== false ? (string)$row['symbol'] : '';
    }

    private static function nowIso(): string
    {
        $t     = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\\TH:i:s.', (int)$t) . $micro . 'Z';
    }

    // ──────────────────────────────────────────────────────────────────────
    // v0.8.0.4: ручное закрытие активных позиций и отмена PENDING_CONDITIONAL
    //           для live/testnet. Совместимо по сигнатуре с PaperAdapter.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Ручная отмена PENDING_CONDITIONAL для live/testnet.
     *
     * Отменяет conditional-ордер на бирже (по order_link_id_open),
     * проставляет trade.status='CANCELLED' и помечает связанные orders как cancelled.
     */
    public function cancelPendingManual(int $tradeId): array
    {
        $pdo = Database::pdo();
        $now = self::nowIso();

        $stmt = $pdo->prepare(
            "SELECT id, symbol, order_link_id_open, order_id_open FROM trades WHERE id = :id"
        );
        $stmt->execute([':id' => $tradeId]);
        $trade = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($trade === false) {
            return ['ok' => false, 'error' => 'trade_not_found'];
        }

        $linkId = (string)($trade['order_link_id_open'] ?? '');
        $oid    = (string)($trade['order_id_open'] ?? '');
        $idForCancel = $linkId !== '' ? $linkId : $oid;

        $cancelOk = false;
        $cancelErr = null;
        if ($idForCancel !== '') {
            try {
                $cancelOk = $this->cancelOrder($idForCancel);
            } catch (\Throwable $e) {
                $cancelErr = $e->getMessage();
                Logger::get()->warning('bybit_adapter: cancelPendingManual cancelOrder threw', [
                    'trade_id' => $tradeId, 'msg' => $cancelErr,
                ]);
            }
        } else {
            Logger::get()->warning('bybit_adapter: cancelPendingManual — нет order_link_id_open/order_id_open', [
                'trade_id' => $tradeId,
            ]);
        }

        // В любом случае помечаем trade как CANCELLED локально (биржа могла уже сама
        // закрыть/исполнить ордер; статус сверится при reconcile).
        $pdo->prepare(
            "UPDATE trades SET status = 'CANCELLED', closed_at = :now WHERE id = :id"
        )->execute([':now' => $now, ':id' => $tradeId]);

        $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', cancelled_at = :now
             WHERE trade_id = :tid AND exchange = :exch AND status IN ('placed','pending')"
        )->execute([':tid' => $tradeId, ':now' => $now, ':exch' => $this->exchange]);

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'manual_cancel_pending', [
            'exchange'  => $this->exchange,
            'cancel_ok' => $cancelOk,
            'error'     => $cancelErr,
        ]);

        return [
            'ok'        => true,
            'reason'    => 'manual_cancel',
            'cancel_ok' => $cancelOk,
            'error'     => $cancelErr,
        ];
    }

    /**
     * Ручное закрытие открытой/усреднённой позиции для live/testnet.
     *
     * Делегирует в closePosition($tradeId, 'manual'), который посылает reduce-only
     * market-ордер и обновляет positions/trades.
     */
    public function closePositionManual(int $tradeId): array
    {
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT id FROM positions WHERE trade_id = :tid AND exchange = :exch AND closed_at IS NULL LIMIT 1"
        );
        $stmt->execute([':tid' => $tradeId, ':exch' => $this->exchange]);
        $pos = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($pos === false) {
            return ['ok' => false, 'error' => 'no_open_position'];
        }

        $this->closePosition($tradeId, 'manual');

        return [
            'ok'     => true,
            'reason' => 'manual',
        ];
    }

    /**
     * Вычислить triggerDirection для conditional ордера исходя из расстояния trigger price от last price.
     *
     * Bybit V5 требует флаг:
     *   1 = Rise  — сработать когда last «поднимется» до trigger (trigger выше текущей цены).
     *   2 = Fall  — сработать когда last «опустится» до trigger (trigger ниже текущей цены).
     *
     * @return int 1|2
     * @throws \RuntimeException если не удалось получить last price
     */
    private function resolveTriggerDirection(string $symbol, float $triggerPx): int
    {
        $resp = $this->client->getTickers24h($symbol);
        if (($resp['category'] ?? null) !== Errors::SUCCESS) {
            throw new \RuntimeException(
                'BybitAdapter: не удалось получить текущую цену для triggerDirection (' . $symbol . ')'
            );
        }
        $last = isset($resp['result']['list'][0]['lastPrice']) ? (float)$resp['result']['list'][0]['lastPrice'] : 0.0;
        if ($last <= 0) {
            throw new \RuntimeException(
                'BybitAdapter: lastPrice=0 или отсутствует в ответе tickers (' . $symbol . ')'
            );
        }
        // Если trigger ровно равен last — движения не будет ни в какую сторону; выбираем 1 как по умолчанию.
        return $triggerPx > $last ? 1 : 2;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // v0.8.0.14: вспомогательные методы для автоопределения position mode
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Рассчитать positionIdx по Bybit V5:
     *   one-way mode (hedge=false)  → 0
     *   hedge mode    (hedge=true)   → Buy:1, Sell:2
     */
    private static function resolvePositionIdx(string $side, bool $hedge): int
    {
        if (!$hedge) {
            return 0;
        }
        return (strtolower($side) === 'buy') ? 1 : 2;
    }

    /**
     * Определить, является ли ошибка 'position idx not match position mode'.
     * Bybit V5: retCode=10001 и retMsg содержит строку. На всякий случай
     * проверяем и по тексту.
     */
    private static function isPositionIdxMismatch(array $resp): bool
    {
        $msg = strtolower((string)($resp['ret_msg'] ?? $resp['retMsg'] ?? ''));
        if ($msg !== '' && strpos($msg, 'position idx not match') !== false) {
            return true;
        }
        // Альтернативные формулировки
        if ($msg !== '' && strpos($msg, 'position mode') !== false && strpos($msg, 'not match') !== false) {
            return true;
        }
        return false;
    }

    /**
     * v0.8.0.14 / v0.9.0: публичный helper для диагностики. Возвращает текущий
     * кэшированный режим, или null если не определён.
     *
     * @param string $accountKey 'acc:{id}' или 'legacy:{exchange}'. Для обратной
     *  совместимости default = 'legacy:live'.
     */
    public static function getCachedHedgeMode(string $symbol, string $accountKey = 'legacy:live'): ?bool
    {
        return self::$hedgeModeCache[$accountKey][$symbol] ?? null;
    }

    /**
     * v0.8.0.14 / v0.9.0: публичный helper для принудительной установки (диагностика).
     */
    public static function setHedgeMode(string $symbol, bool $hedge, string $accountKey = 'legacy:live'): void
    {
        if (!isset(self::$hedgeModeCache[$accountKey])) {
            self::$hedgeModeCache[$accountKey] = [];
        }
        self::$hedgeModeCache[$accountKey][$symbol] = $hedge;
    }
}
