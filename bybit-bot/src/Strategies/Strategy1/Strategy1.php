<?php
declare(strict_types=1);

namespace BybitBot\Strategies\Strategy1;

use BybitBot\Bybit\Errors;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use BybitBot\Core\Rounding;
use BybitBot\Guards\DailyDrawdownGuard;
use BybitBot\Guards\FundingFilterGuard;
use BybitBot\Guards\LongShortBalanceGuard;
use BybitBot\Guards\MaxOpenPositionsGuard;
use BybitBot\Guards\MaxTotalOrdersGuard;
use BybitBot\Guards\MinLotOvershootGuard;
use BybitBot\Guards\SignalUpperCapGuard;
use BybitBot\Signals\Decisions;
use BybitBot\Strategies\StrategyInterface;

/**
 * Strategy 1 — автоматическая стратегия по signalsHourly.
 *
 * Реализует полный жизненный цикл:
 *  - collectAutoIntents()     — выбор сигнала из БД + все проверки + расчёт ордера
 *  - buildConditionalOrder()  — финальный расчёт entry/TP/SL/qty/leverage
 *  - onPositionOpened()       — SL_real + trailing + avg (§6.2–§6.4)
 *  - onPositionAveraged()     — P_BE + trailing 1%/2% + SL post_avg (§7)
 *
 * Свежесть сигнала (§3.1 / Уточнения-5):
 *   signal.date == today_msk И HOUR(signal.time) == current_hour_msk
 *
 * Формула лота (§5.4, v0.7.0): плечо НЕ уменьшает qty, влияет только на маржу.
 *   leverage         = min(max_leverage_bybit, leverage_cap)
 *   base_lot_usdt    = 0.01 × deposit_anchor
 *   notional_usdt    = base_lot_usdt × 100 / p_TP_used
 *   order_qty_raw    = notional_usdt / entry_ref
 *   order_qty_safe   = order_qty_raw × (1 − qty_safety_margin_pct/100)
 *   order_qty_coins  = floor_to_step(order_qty_safe, qtyStep)
 *   order_margin     = (order_qty_coins × entry_ref) / leverage
 *
 * avg_qty (§6.4 / Уточнения-6):
 *   avg_qty = current_qty (на момент постановки) × 2 × market_coef
 *
 * См. spec.md §3.1, §4, §5, §6, §7.
 */
final class Strategy1 implements StrategyInterface
{
    /**
     * Хардкод-список стейблкоинов (base_coin). Дополняется из settings.stablecoins_blacklist.
     * Уточнения-7.
     */
    private const DEFAULT_STABLECOINS = [
        'USDT', 'USDC', 'DAI', 'FDUSD', 'BUSD', 'TUSD', 'USDD', 'PYUSD',
        'EURC', 'EURT', 'USDP', 'GUSD', 'FRAX', 'LUSD', 'SUSD',
    ];

    public function id(): string
    {
        return 's1';
    }

    public function name(): string
    {
        return 'Strategy 1 — авто signalsHourly';
    }

    public function isAutomatic(): bool
    {
        return true;
    }

    /**
     * Собрать намерения на постановку conditional ордеров.
     *
     * @param array $context {
     *   adapter:     ExchangeAdapter,
     *   deposit_anchor: float,
     *   current_balance: float,
     *   positions:   array,
     *   open_orders: array,
     * }
     * @return array<int, array> Список intents (0 или 1 элемент для S1)
     */
    public function collectAutoIntents(array $context): array
    {
        $signals = $this->loadFreshSignals();

        if (empty($signals)) {
            Logger::get()->info('s1: нет свежих сигналов в БД для текущего часа MSK');
            return [];
        }

        Logger::get()->info('s1: загружено сигналов', ['count' => count($signals)]);

        // Получить стейблкоины из настроек
        $stablecoins = $this->getStablecoinsBlacklist();

        // Фильтрация кандидатов
        $candidates = [];
        foreach ($signals as $sig) {
            $reason = $this->filterSignal($sig, $stablecoins, $context);
            if ($reason !== null) {
                EventRecorder::event(EventRecorder::INFO, $reason, (string)($sig['bybit_symbol'] ?? $sig['symbol']), [
                    'signal_id' => $sig['id'],
                    'side'      => $sig['side'],
                    'target'    => $sig['target'],
                ]);
                // v0.7.7: фиксируем решение для этого сигнала.
                Decisions::record(
                    (int)($sig['id'] ?? 0),
                    Decisions::REJECTED_FILTER,
                    $reason
                );
                continue;
            }
            $candidates[] = $sig;
        }

        if (empty($candidates)) {
            Logger::get()->info('s1: нет кандидатов после фильтрации');
            return [];
        }

        // v0.8.0.14: ранжируем ВСЕ кандидаты по приоритету chooseBestSignal,
        // затем строим intents для каждого. cron_hourly при фейле размещения
        // первого попробует второй, третий — fallback до 3 попыток.
        $ranked = $this->rankCandidates($candidates);
        if (empty($ranked)) {
            return [];
        }

        Logger::get()->info('s1: ранжировано кандидатов', [
            'count' => count($ranked),
            'top'   => array_slice(array_map(static function (array $r): array {
                return [
                    'symbol' => $r['bybit_symbol'] ?? $r['symbol'] ?? null,
                    'side'   => $r['side'] ?? null,
                    'target' => $r['target'] ?? null,
                ];
            }, $ranked), 0, 5),
        ]);

        $intents = [];
        $rank    = 0;
        foreach ($ranked as $chosen) {
            $rank++;
            $intent = [
                'strategy_id' => 's1',
                'symbol'      => (string)($chosen['bybit_symbol'] ?? $chosen['symbol']),
                'side'        => (string)$chosen['side'],
                'target_pct'  => abs((float)$chosen['target']),
                'signal_id'   => (int)$chosen['id'],
                'signal_w7'   => $chosen['w7'] ?? null,
                'signal_rsi'  => $chosen['rsi'] ?? null,
                'rank'        => $rank,
            ];

            $guardResults = $this->runGuards($intent, $context);
            if (!empty($guardResults)) {
                foreach ($guardResults as $g) {
                    EventRecorder::event(
                        $g['critical'] ? EventRecorder::WARN : EventRecorder::INFO,
                        'guard_blocked',
                        $intent['symbol'],
                        ['guard' => $g['guard'], 'message' => $g['message'], 'rank' => $rank]
                    );
                }
                Logger::get()->info('s1: кандидат заблокирован guards', [
                    'symbol' => $intent['symbol'],
                    'rank'   => $rank,
                    'guards' => array_column($guardResults, 'guard'),
                ]);
                Decisions::record(
                    (int)$intent['signal_id'],
                    Decisions::REJECTED_GUARD,
                    implode(', ', array_column($guardResults, 'guard'))
                );
                continue;
            }

            try {
                $orderParams = $this->buildConditionalOrder($intent, $context, $chosen);
                $orderParams['rank'] = $rank;
                $intents[] = $orderParams;
            } catch (\Throwable $e) {
                EventRecorder::event(EventRecorder::WARN, 'order_build_failed', $intent['symbol'], [
                    'signal_id' => $intent['signal_id'],
                    'rank'      => $rank,
                    'error'     => $e->getMessage(),
                ]);
                Logger::get()->warning('s1: buildConditionalOrder failed', [
                    'symbol' => $intent['symbol'],
                    'rank'   => $rank,
                    'error'  => $e->getMessage(),
                ]);
                Decisions::record(
                    (int)$intent['signal_id'],
                    Decisions::REJECTED_OTHER,
                    'order_build_failed: ' . $e->getMessage()
                );
            }
        }

        Logger::get()->info('s1: построено intents для fallback-цепочки', [
            'count' => count($intents),
        ]);

        return $intents;
    }

    /**
     * v0.8.0.14: ранжировать всех кандидатов по той же логике, что
     * раньше использовалась в chooseBestSignal — но вернуть СПИСОК, а не один.
     * Используется для fallback-цепочки в cron_hourly.
     */
    private function rankCandidates(array $candidates): array
    {
        if (empty($candidates)) {
            return [];
        }

        $weightsMode = (string)Config::get('weights_mode', 's1', 'priority');

        if ($weightsMode === 'w7_only') {
            $filtered = [];
            foreach ($candidates as $c) {
                $side = (string)$c['side'];
                $w7   = (int)($c['w7'] ?? 0);
                if ($side === 'long' && $w7 > 0) {
                    $filtered[] = $c;
                } elseif ($side === 'short' && $w7 < 0) {
                    $filtered[] = $c;
                }
            }
            $candidates = $filtered;
        }

        if (empty($candidates)) {
            return [];
        }

        if ($weightsMode === 'disabled') {
            return $this->sortByMaxTarget($candidates);
        }

        // priority_w7: сначала "правильные" по w7, затем остальные;
        // внутри групп — по |target| с тай-брейкерами.
        $withGoodW7 = [];
        $rest       = [];
        foreach ($candidates as $c) {
            $side = (string)$c['side'];
            $w7   = (int)($c['w7'] ?? 0);
            if (($side === 'long' && $w7 > 0) || ($side === 'short' && $w7 < 0)) {
                $withGoodW7[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        return array_merge(
            $this->sortByMaxTarget($withGoodW7),
            $this->sortByMaxTarget($rest)
        );
    }

    /**
     * v0.8.0.14: та же сортировка, что в pickByMaxTarget, но возвращает
     * полный отсортированный массив.
     */
    private function sortByMaxTarget(array $pool): array
    {
        if (empty($pool)) {
            return [];
        }
        usort($pool, static function (array $a, array $b): int {
            $ta = abs((float)$a['target']);
            $tb = abs((float)$b['target']);
            if (abs($ta - $tb) > 0.0001) {
                return $ta > $tb ? -1 : 1;
            }
            $wa = abs((int)($a['w7'] ?? 0));
            $wb = abs((int)($b['w7'] ?? 0));
            if ($wa !== $wb) {
                return $wa > $wb ? -1 : 1;
            }
            $sideA = (string)$a['side'];
            $ra    = (float)($a['rsi'] ?? 50.0);
            $rb    = (float)($b['rsi'] ?? 50.0);
            if (abs($ra - $rb) > 0.001) {
                return ($sideA === 'long') ? ($ra < $rb ? -1 : 1) : ($ra > $rb ? -1 : 1);
            }
            return strcmp((string)($a['symbol'] ?? ''), (string)($b['symbol'] ?? ''));
        });
        return $pool;
    }

    /**
     * Рассчитать параметры conditional ордера (entry/TP/SL/qty/leverage).
     *
     * @param array $intent  Базовые поля (symbol, side, target_pct, signal_id)
     * @param array $context Контекст (adapter, deposit_anchor)
     * @param array $signal  Строка из signals
     * @return array Готовые параметры для adapter->placeConditional()
     */
    public function buildConditionalOrder(array $intent, array $context, array $signal = []): array
    {
        $symbol    = (string)$intent['symbol'];
        $side      = (string)$intent['side']; // 'long'|'short'
        $p         = abs((float)$intent['target_pct']);
        $signSign  = ($side === 'long') ? 1.0 : -1.0;
        $isLong    = ($side === 'long');

        /** @var \BybitBot\Exchange\ExchangeAdapter $adapter */
        $adapter   = $context['adapter'];

        // Информация о инструменте
        $instrInfo = $adapter->getInstrumentInfo($symbol);
        $tickSize  = (float)$instrInfo['tickSize'];
        $qtyStep   = (float)$instrInfo['qtyStep'];
        $qtyMin    = (float)$instrInfo['qtyMin'];
        $maxLevBybit = (float)$instrInfo['maxLeverage'];

        // §5.2: entry_ref — берём 2 часовые свечи
        $klines = $adapter->getKline($symbol, '60', 2);
        if (count($klines) < 1) {
            throw new \RuntimeException("Нет kline-данных для {$symbol}");
        }

        $candle0  = $klines[0]; // текущая (первая из списка, новейшая)
        $candle1  = isset($klines[1]) ? $klines[1] : $klines[0]; // предыдущая

        if ($isLong) {
            $refPrice  = max((float)$candle0['high'], (float)$candle1['high']);
            $deltaDir  = Rounding::UP;
        } else {
            $refPrice  = min((float)$candle0['low'], (float)$candle1['low']);
            $deltaDir  = Rounding::DOWN;
        }

        // §5.1: delta_pct через amplitude
        $deltaPctOfAmplitude = (float)Config::get('delta_pct_of_amplitude', 's1', 0.08);
        $lookback            = (int)Config::get('delta_lookback_candles',    's1', 24);

        // Берём amplitude из последних $lookback свечей
        $allKlines  = $adapter->getKline($symbol, '60', $lookback);
        $amplitude  = $this->calcAmplitudePct($allKlines);

        $deltaPct   = $deltaPctOfAmplitude * $amplitude;

        // entry_ref
        if ($isLong) {
            $entryRaw = $refPrice * (1 + $deltaPct / 100);
            $entryRef = Rounding::roundToStep($entryRaw, $tickSize, Rounding::UP);
        } else {
            $entryRaw = $refPrice * (1 - $deltaPct / 100);
            $entryRef = Rounding::roundToStep($entryRaw, $tickSize, Rounding::DOWN);
        }

        // §5.3: TP_init и SL_init
        if ($isLong) {
            $tpRaw   = $entryRef * (1 + $p / 100);
            $slRaw   = $entryRef * (1 - $p / 100);
            $tpInit  = Rounding::roundToStep($tpRaw, $tickSize, Rounding::DOWN);
            $slInit  = Rounding::roundToStep($slRaw, $tickSize, Rounding::UP);
        } else {
            $tpRaw   = $entryRef * (1 - $p / 100);
            $slRaw   = $entryRef * (1 + $p / 100);
            $tpInit  = Rounding::roundToStep($tpRaw, $tickSize, Rounding::UP);
            $slInit  = Rounding::roundToStep($slRaw, $tickSize, Rounding::DOWN);
        }

        // Реальный p_TP_used (после округления TP к tickSize)
        if ($entryRef > 0) {
            $pTpUsed = abs(($tpInit - $entryRef) / $entryRef) * 100.0;
        } else {
            throw new \RuntimeException("Нулевой entry_ref для {$symbol}");
        }
        if ($pTpUsed < 0.01) {
            $pTpUsed = $p; // fallback: если округление свело к 0
        }

        // §5.4: плечо (Уточнения-8)
        $leverageCapEnabled = filter_var(Config::get('leverage_cap.enabled', 's1', false), FILTER_VALIDATE_BOOLEAN);
        $leverageCapValue   = (int)Config::get('leverage_cap',               's1', 50);

        $maxLev = (int)floor($maxLevBybit);
        if ($leverageCapEnabled && $leverageCapValue > 0 && $leverageCapValue < $maxLev) {
            $maxLev = $leverageCapValue;
        } elseif (!$leverageCapEnabled) {
            // Если cap не включён, применяем дефолтное значение как ограничение
            $capDefault = (int)Config::get('leverage_cap', 's1', 50);
            if ($capDefault > 0 && $capDefault < $maxLev) {
                $maxLev = $capDefault;
            }
        }

        $leverage = max(1, $maxLev);

        // §5.4 (v0.7.0): размер лота — плечо НЕ участвует в qty.
        // notional_usdt — полный номинал позиции, рассчитанный так чтобы при срабатывании SL_init потеря = base_lot_usdt (1% депозита).
        // Плечо влияет только на занимаемую маржу: margin = notional / leverage.
        $depositAnchor    = (float)($context['deposit_anchor'] ?? 300.0);
        $baseLotUsdt      = 0.01 * $depositAnchor;
        $notionalUsdt     = $baseLotUsdt * 100.0 / $pTpUsed;
        $orderQtyCoinsRaw = $notionalUsdt / $entryRef;

        // §5.4: safety-зазор (защита от проскальзываний и округлений Bybit).
        $safetyPct = (float)Config::get('qty_safety_margin_pct', null, 10.0);
        if ($safetyPct < 0.0)  { $safetyPct = 0.0; }
        if ($safetyPct > 50.0) { $safetyPct = 50.0; }
        $orderQtyCoinsSafe = $orderQtyCoinsRaw * (1.0 - $safetyPct / 100.0);

        // Округление qty
        if ($orderQtyCoinsSafe < $qtyMin) {
            $orderQtyCoins = Rounding::roundToStep($qtyMin, $qtyStep, Rounding::UP);
        } else {
            $orderQtyCoins = Rounding::roundToStep($orderQtyCoinsSafe, $qtyStep, Rounding::DOWN);
        }

        // Алиас для обратной совместимости с min_lot_overshoot guard и логами
        $unleveragedUsdt = $notionalUsdt;
        $orderQtyUsdt    = $orderQtyCoins * $entryRef; // фактический номинал после округления

        // Проверка min_lot_overshoot через guard
        $overshootIntent = array_merge($intent, [
            'qty_min'         => $qtyMin,
            'entry_ref'       => $entryRef,
            'unleveraged_usdt'=> $unleveragedUsdt,
        ]);
        $overshootGuard = new MinLotOvershootGuard();
        $overshootResult = $overshootGuard->check($overshootIntent, $context);
        if (!empty($overshootResult)) {
            throw new \RuntimeException('SKIPPED_TOO_SMALL: ' . $overshootResult[0]['message']);
        }

        // Формируем orderLinkId (idempotency)
        $nano       = bin2hex(random_bytes(4));
        $mode       = (string)Config::get('mode', null, 'paper');
        $tmpTradeId = $context['tmp_trade_id'] ?? 'new';
        $linkId     = "s1-{$tmpTradeId}-entry-{$nano}";

        // Bybit side: long=Buy, short=Sell
        $bybitSide = $isLong ? 'Buy' : 'Sell';

        // v0.8.0.3: triggerDirection больше не выводим из long/short — это неправильно для Bybit V5.
        // BybitAdapter вычисляет его автоматически по расположению trigger относительно last price.
        $triggerDirection = null;

        // margin_mode из настроек (Уточнения-4)
        $marginMode = (string)Config::get('bybit_margin_mode', 's1', 'cross');

        return [
            // Идентификация
            'strategy_id'      => 's1',
            'signal_id'        => (int)($intent['signal_id'] ?? 0),
            'symbol'           => $symbol,
            'mode'             => $mode,

            // Ордер
            'side'             => $bybitSide,     // 'Buy'|'Sell'
            'trade_side'       => $side,           // 'long'|'short'
            'order_type'       => 'Market',
            'qty'              => $orderQtyCoins,
            'trigger_price'    => $entryRef,

            // TP/SL
            'tp_price'         => $tpInit,
            'sl_price'         => $slInit,

            // Идемпотентность
            'order_link_id'    => $linkId,

            // Расчётные параметры
            'entry_ref'        => $entryRef,
            'p_tp_used'        => $pTpUsed,
            'leverage'         => $leverage,
            'margin_mode'      => $marginMode,
            'deposit_anchor'   => $depositAnchor,
            'base_lot_usdt'    => $baseLotUsdt,
            'unleveraged_usdt' => $unleveragedUsdt,
            'order_qty_usdt'   => $orderQtyUsdt,

            // Для кеша в trades
            'signal_target_pct'=> $p,
            'signal_w7'        => $intent['signal_w7'] ?? null,
            'signal_rsi'       => $intent['signal_rsi'] ?? null,
            'sl_init'          => $slInit,
            'tp_init'          => $tpInit,
            'p_for_strategy_calc' => $p,
            'qty_min'          => $qtyMin,
        ];
    }

    /**
     * Хук после перехода сделки в OPEN.
     * Рассчитывает SL_real (§6.2), trailing (§6.3), avg (§6.4).
     *
     * @param array $trade   Строка из trades (с entry_real, qty_current, leverage, etc.)
     * @param array $context {adapter, deposit_anchor, instrument_info}
     */
    public function onPositionOpened(array $trade, array $context): void
    {
        $tradeId = (int)$trade['id'];
        $symbol  = (string)$trade['symbol'];
        $side    = (string)$trade['side'];  // 'long'|'short'
        $isLong  = ($side === 'long');
        $sign    = $isLong ? 1.0 : -1.0;

        $pReal   = isset($trade['entry_real'])      ? (float)$trade['entry_real']  : null;
        $p       = isset($trade['p_for_strategy_calc'])? (float)$trade['p_for_strategy_calc'] : null;
        $qty     = isset($trade['qty_current'])     ? (float)$trade['qty_current'] : (float)($trade['qty_initial'] ?? 0);

        if ($pReal === null || $p === null || $pReal <= 0) {
            Logger::get()->warning('s1.onPositionOpened: нет entry_real или p', ['trade_id' => $tradeId]);
            return;
        }

        /** @var \BybitBot\Exchange\ExchangeAdapter $adapter */
        $adapter   = $context['adapter'];
        $marketCoef = (float)Config::get('market_coef', 's1', 1.35);

        $instrInfo = $adapter->getInstrumentInfo($symbol);
        $tickSize  = (float)$instrInfo['tickSize'];
        $qtyStep   = (float)$instrInfo['qtyStep'];
        $qtyMin    = (float)$instrInfo['qtyMin'];

        // §6.2: SL_real
        // SL_real = P_real - (P_real × sign × p × 2 × market_coef) / 100
        $slRealRaw = $pReal - ($pReal * $sign * $p * 2.0 * $marketCoef) / 100.0;
        if ($isLong) {
            $slReal = Rounding::roundToStep($slRealRaw, $tickSize, Rounding::UP);
        } else {
            $slReal = Rounding::roundToStep($slRealRaw, $tickSize, Rounding::DOWN);
        }

        // §6.3: trailing
        // movement_coef = наименьшее k ≥ 0 такое, что 2^k ≥ p; если p < 2 → k=1
        $movementCoef = 1;
        if ($p >= 2.0) {
            $k = 0;
            while (pow(2.0, $k) < $p) {
                $k++;
            }
            $movementCoef = (int)pow(2, $k);
        }

        $trailingPct      = Rounding::floorToTenth($p / (float)$movementCoef - 0.3);
        $trailingPct      = max(0.1, $trailingPct); // минимум 0.1%
        $triggerPriceRaw  = $pReal + ($pReal * $sign * ($p / (float)$movementCoef)) / 100.0;

        if ($isLong) {
            $triggerPrice = Rounding::roundToStep($triggerPriceRaw, $tickSize, Rounding::DOWN);
        } else {
            $triggerPrice = Rounding::roundToStep($triggerPriceRaw, $tickSize, Rounding::UP);
        }

        // §6.4: avg
        // avg_price = P_real - (P_real × sign × p × 1.5 × market_coef) / 100
        $avgPriceRaw = $pReal - ($pReal * $sign * $p * 1.5 * $marketCoef) / 100.0;
        if ($isLong) {
            $avgPrice = Rounding::roundToStep($avgPriceRaw, $tickSize, Rounding::DOWN);
        } else {
            $avgPrice = Rounding::roundToStep($avgPriceRaw, $tickSize, Rounding::UP);
        }

        // avg_qty = current_qty × 2 × market_coef (Уточнения-6)
        $avgQtyRaw = $qty * 2.0 * $marketCoef;
        $avgQty    = Rounding::roundToStep($avgQtyRaw, $qtyStep, Rounding::UP);
        if ($avgQty < $qtyMin) {
            $avgQty = Rounding::roundToStep($qtyMin, $qtyStep, Rounding::UP);
        }

        // Обновляем BД
        $pdo = Database::pdo();
        $pdo->prepare(
            'UPDATE trades SET sl_current = :sl, trailing_pct = :tpct, trailing_trigger = :ttrig,
                               avg_price = :ap, qty_avg = :aq
             WHERE id = :id'
        )->execute([
            ':sl'    => $slReal,
            ':tpct'  => $trailingPct,
            ':ttrig' => $triggerPrice,
            ':ap'    => $avgPrice,
            ':aq'    => $avgQty,
            ':id'    => $tradeId,
        ]);

        // Ставим SL/trailing через адаптер.
        // v0.8.0.7: clear_tp=true — снимаем статичный TP, который Bybit выставил
        // при исполнении conditional (tp_init был передан в placeConditional).
        // Стратегия s1 работает с трейлинг-стопом, а не с точечным TP.
        // v0.8.0.15: проверяем результат setTradingStop; адаптер сам логирует
        // bybit_trading_stop_set / bybit_trading_stop_failed в trade_events.
        $bybitSide = $isLong ? 'Buy' : 'Sell';
        $slOk = $adapter->setTradingStop($symbol, $bybitSide, [
            'sl_price'              => $slReal,
            'trailing_pct'          => $trailingPct,
            'trailing_trigger_price'=> $triggerPrice,
            'clear_tp'              => true,
            'trade_id'              => $tradeId,
            'context'               => [
                'purpose'      => 'on_position_opened',
                'entry_real'   => $pReal,
                'p'            => $p,
                'market_coef'  => $marketCoef,
                'tick_size'    => $tickSize,
                'side'         => $side,
            ],
        ]);
        if (!$slOk) {
            EventRecorder::tradeEvent($tradeId, EventRecorder::ERROR, 'sl_real_setup_failed', [
                'symbol'        => $symbol,
                'side'          => $side,
                'sl_real'       => $slReal,
                'entry_real'    => $pReal,
                'p'             => $p,
                'market_coef'   => $marketCoef,
                'note'          => 'Исходящий sl_init остался на бирже без изменений; локальный sl_current обновлён по §6.2.',
            ]);
            Logger::get()->warning("s1.onPositionOpened: SL_real не принят биржей", [
                'trade_id' => $tradeId, 'symbol' => $symbol, 'sl_real' => $slReal,
            ]);
        }

        // Ставим avg conditional
        $nano    = bin2hex(random_bytes(4));
        $linkAvg = "s1-{$tradeId}-avg-{$nano}";

        $avgSide = $isLong ? 'Buy' : 'Sell';

        $adapter->placeConditional([
            'trade_id'      => $tradeId,
            'symbol'        => $symbol,
            'side'          => $avgSide,
            'qty'           => $avgQty,
            'trigger_price' => $avgPrice,
            'purpose'       => 'avg',
            'order_link_id' => $linkAvg,
        ]);

        // v0.8.0.5: раньше здесь был второй INSERT INTO orders с хардкодом paper=1 и
        // без exchange — он дублировал запись, которую уже сделал $adapter->placeConditional()
        // (причём у live-сделок вторая запись ошибочно помечалась как paper). Удален.

        // Обновляем order_link_id_avg в trades
        $pdo->prepare(
            'UPDATE trades SET order_link_id_avg = :link WHERE id = :id'
        )->execute([':link' => $linkAvg, ':id' => $tradeId]);

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'position_opened_setup', [
            'sl_real'        => $slReal,
            'trailing_pct'   => $trailingPct,
            'trigger_price'  => $triggerPrice,
            'avg_price'      => $avgPrice,
            'avg_qty'        => $avgQty,
        ]);

        Logger::get()->info("s1: onPositionOpened для trade #{$tradeId}", [
            'symbol'       => $symbol,
            'entry_real'   => $pReal,
            'sl_real'      => $slReal,
            'trailing_pct' => $trailingPct,
            'avg_price'    => $avgPrice,
        ]);
    }

    /**
     * Хук после усреднения (§7).
     *
     * @param array $trade   Строка из trades (с данными по усреднению)
     * @param array $context {adapter, deposit_anchor, instrument_info}
     */
    public function onPositionAveraged(array $trade, array $context): void
    {
        $tradeId = (int)$trade['id'];
        $symbol  = (string)$trade['symbol'];
        $side    = (string)$trade['side'];
        $isLong  = ($side === 'long');
        $sign    = $isLong ? 1.0 : -1.0;

        $p1     = (float)($trade['entry_real'] ?? 0);
        $q1     = (float)($trade['qty_initial'] ?? 0);
        $p2     = (float)($trade['avg_price'] ?? 0);
        $q2     = (float)($trade['qty_avg'] ?? 0);

        if ($p1 <= 0 || $q1 <= 0 || $p2 <= 0 || $q2 <= 0) {
            Logger::get()->warning('s1.onPositionAveraged: неполные данные по ценам/qty', ['trade_id' => $tradeId]);
            return;
        }

        $takerFee      = (float)Config::get('taker_fee_pct',  's1', 0.055) / 100.0;
        $depositAnchor = (float)($context['deposit_anchor'] ?? 300.0);

        // §7.1: P_BE
        $feesOpen     = ($q1 * $p1 * $takerFee) + ($q2 * $p2 * $takerFee);
        $pBeProvis    = ($p1 * $q1 + $p2 * $q2) / ($q1 + $q2);
        $feesClose    = ($q1 + $q2) * $pBeProvis * $takerFee;

        // Funding (если есть)
        $funding = $this->loadFundingTotal($tradeId);

        $pBe = ($p1 * $q1 + $p2 * $q2 + $sign * ($feesOpen + $feesClose + $funding)) / ($q1 + $q2);

        // §7.2: trailing после усреднения
        $trailingPctAvg   = 1.0;
        $triggerPriceRaw  = $pBe + $sign * $pBe * 2.0 / 100.0;

        // §7.3: SL post_avg (защита 8% депо)
        $maxLossUsdt  = 0.08 * $depositAnchor;
        $slDistance   = $maxLossUsdt / ($q1 + $q2);
        $slPostAvg    = $pBe - $sign * $slDistance;

        // Защита: не ухудшать SL
        $currentSl    = isset($trade['sl_current']) ? (float)$trade['sl_current'] : null;
        if ($currentSl !== null) {
            if ($isLong && $slPostAvg < $currentSl) {
                $slPostAvg = $currentSl; // не двигаем SL в убыточную сторону
            } elseif (!$isLong && $slPostAvg > $currentSl) {
                $slPostAvg = $currentSl;
            }
        }

        // Информация о инструменте для округления
        /** @var \BybitBot\Exchange\ExchangeAdapter $adapter */
        $adapter  = $context['adapter'];
        $instrInfo = $adapter->getInstrumentInfo($symbol);
        $tickSize  = (float)$instrInfo['tickSize'];

        if ($isLong) {
            $triggerPrice = Rounding::roundToStep($triggerPriceRaw, $tickSize, Rounding::DOWN);
            $slPostAvg    = Rounding::roundToStep($slPostAvg,        $tickSize, Rounding::UP);
        } else {
            $triggerPrice = Rounding::roundToStep($triggerPriceRaw, $tickSize, Rounding::UP);
            $slPostAvg    = Rounding::roundToStep($slPostAvg,        $tickSize, Rounding::DOWN);
        }

        // Обновляем БД
        $pdo = Database::pdo();
        $pdo->prepare(
            'UPDATE trades SET break_even_price = :be, sl_current = :sl, trailing_pct = :tpct,
                               trailing_trigger = :ttrig, qty_current = :qc, status = :s
             WHERE id = :id'
        )->execute([
            ':be'    => $pBe,
            ':sl'    => $slPostAvg,
            ':tpct'  => $trailingPctAvg,
            ':ttrig' => $triggerPrice,
            ':qc'    => $q1 + $q2,
            ':s'     => 'AVERAGED',
            ':id'    => $tradeId,
        ]);

        // Применяем через адаптер
        // v0.8.0.8: симметрично onPositionOpened — явно снимаем статичный TP
        // (clear_tp=true). На всякий случай, если при onPositionOpened он не был снят
        // (например вручную выставлен или поставлен биржевым механизмом).
        // v0.8.0.15: проверяем результат setTradingStop и логируем при фейле.
        $bybitSide = $isLong ? 'Buy' : 'Sell';
        $slOk = $adapter->setTradingStop($symbol, $bybitSide, [
            'sl_price'              => $slPostAvg,
            'trailing_pct'          => $trailingPctAvg,
            'trailing_trigger_price'=> $triggerPrice,
            'clear_tp'              => true,
            'trade_id'              => $tradeId,
            'context'               => [
                'purpose'      => 'on_position_averaged',
                'p_be'         => $pBe,
                'sl_post_avg'  => $slPostAvg,
                'side'         => $side,
            ],
        ]);
        if (!$slOk) {
            EventRecorder::tradeEvent($tradeId, EventRecorder::ERROR, 'sl_post_avg_setup_failed', [
                'symbol'      => $symbol,
                'side'        => $side,
                'sl_post_avg' => $slPostAvg,
                'p_be'        => $pBe,
                'note'        => 'Локальный sl_current обновлён по §7.3, но биржа не приняла стоп.',
            ]);
            Logger::get()->warning("s1.onPositionAveraged: SL post-avg не принят биржей", [
                'trade_id' => $tradeId, 'symbol' => $symbol, 'sl_post_avg' => $slPostAvg,
            ]);
        }

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'position_averaged_setup', [
            'p_be'         => $pBe,
            'sl_post_avg'  => $slPostAvg,
            'trailing_pct' => $trailingPctAvg,
            'trigger_price'=> $triggerPrice,
        ]);

        Logger::get()->info("s1: onPositionAveraged для trade #{$tradeId}", [
            'p_be'       => $pBe,
            'sl_post_avg'=> $slPostAvg,
        ]);
    }

    /**
     * Только для ручных стратегий — S1 автоматическая.
     */
    public function createManualIntent(array $userInput, array $context): array
    {
        throw new \LogicException('Strategy1 — автоматическая, не принимает ручной ввод.');
    }

    // ── Приватные методы ─────────────────────────────────────

    /**
     * Загрузить свежие сигналы из БД.
     * Свежесть (Уточнения-5): date == today_msk И HOUR(time) == current_hour_msk
     *
     * Обрабатывает все типы сигналов из источника (signal_type=1, 2, 3) по единым правилам.
     * signal_type — это тип сигнала источника signalsHourly.json, НЕ привязка к нашим стратегиям.
     *
     * @return array
     */
    private function loadFreshSignals(): array
    {
        $tz       = new \DateTimeZone('Europe/Moscow');
        $nowMsk   = new \DateTime('now', $tz);
        $todayMsk = $nowMsk->format('Y-m-d');
        $hourMsk  = (int)$nowMsk->format('H');

        // HOUR(time) для формата HH:MM:SS
        // Фильтр по signal_type намеренно ОТСУТСТВУЕТ: S1 обрабатывает все типы сигналов (1, 2, 3)
        $stmt = Database::pdo()->prepare(
            "SELECT s.*, bi.base_coin
             FROM signals s
             LEFT JOIN bybit_instruments bi ON bi.symbol = s.bybit_symbol
             WHERE s.date = :today
               AND CAST(SUBSTR(s.time, 1, 2) AS INTEGER) = :hour
               AND s.potential = 0
               AND s.resolution_status = 'resolved'
             ORDER BY ABS(s.target) DESC"
        );
        $stmt->execute([':today' => $todayMsk, ':hour' => $hourMsk]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            EventRecorder::event(EventRecorder::INFO, 'signal_stale_skip', null, [
                'today_msk' => $todayMsk,
                'hour_msk'  => $hourMsk,
            ]);
        }

        return $rows;
    }

    /**
     * Фильтрация одного сигнала.
     * Возвращает null если сигнал пригоден, или строку-причину отклонения.
     */
    private function filterSignal(array $sig, array $stablecoins, array $context): ?string
    {
        $symbol  = (string)($sig['bybit_symbol'] ?? '');
        $baseCoin = strtoupper((string)($sig['base_coin'] ?? ''));

        // Стейблкоины
        if (in_array($baseCoin, $stablecoins, true)) {
            return 'signal_stablecoin_skip';
        }

        // |target| >= min_signal_target_pct
        $minTargetPct = (float)Config::get('min_signal_target_pct', 's1', 2.0);
        if (abs((float)$sig['target']) < $minTargetPct) {
            return 'signal_too_small_target';
        }

        // Нет открытой позиции по этому символу
        $positions = (array)($context['positions'] ?? []);
        foreach ($positions as $pos) {
            $posSymbol = (string)($pos['symbol'] ?? '');
            if ($posSymbol === $symbol) {
                $qty = isset($pos['size']) ? (float)$pos['size'] : (float)($pos['qty'] ?? 0);
                if ($qty > 0) {
                    return 'signal_position_exists';
                }
            }
        }

        return null;
    }

    /**
     * Выбрать один лучший сигнал из кандидатов по алгоритму §4.1.
     *
     * @param array $candidates
     * @return array|null
     */
    private function chooseBestSignal(array $candidates): ?array
    {
        if (empty($candidates)) {
            return null;
        }

        $weightsMode = (string)Config::get('weights_mode', 's1', 'priority');

        if ($weightsMode === 'w7_only') {
            // Режим «обязательно только совпадение w7»
            $filtered = [];
            foreach ($candidates as $c) {
                $side = (string)$c['side'];
                $w7   = (int)($c['w7'] ?? 0);
                if ($side === 'long' && $w7 > 0) {
                    $filtered[] = $c;
                } elseif ($side === 'short' && $w7 < 0) {
                    $filtered[] = $c;
                }
            }
            if (empty($filtered)) {
                return null;
            }
            $candidates = $filtered;
        }

        if ($weightsMode === 'disabled') {
            // Просто максимум по |target|
            return $this->pickByMaxTarget($candidates);
        }

        // Дефолтный режим: priority_w7
        // Сначала ищем с "правильным" знаком w7
        $withGoodW7 = [];
        foreach ($candidates as $c) {
            $side = (string)$c['side'];
            $w7   = (int)($c['w7'] ?? 0);
            if ($side === 'long' && $w7 > 0) {
                $withGoodW7[] = $c;
            } elseif ($side === 'short' && $w7 < 0) {
                $withGoodW7[] = $c;
            }
        }

        $pool = !empty($withGoodW7) ? $withGoodW7 : $candidates;
        return $this->pickByMaxTarget($pool);
    }

    /**
     * Выбрать из пула сигнал с максимальным |target|.
     * Тай-брейкер: больший |w7| в правильную сторону → меньший rsi (long) / больший rsi (short) → символ.
     */
    private function pickByMaxTarget(array $pool): ?array
    {
        if (empty($pool)) {
            return null;
        }

        usort($pool, static function (array $a, array $b): int {
            $ta = abs((float)$a['target']);
            $tb = abs((float)$b['target']);
            if (abs($ta - $tb) > 0.0001) {
                return $ta > $tb ? -1 : 1;
            }

            // Тай-брейкер 1: больший |w7|
            $wa = abs((int)($a['w7'] ?? 0));
            $wb = abs((int)($b['w7'] ?? 0));
            if ($wa !== $wb) {
                return $wa > $wb ? -1 : 1;
            }

            // Тай-брейкер 2: rsi (меньше для long, больше для short)
            $sideA = (string)$a['side'];
            $ra    = (float)($a['rsi'] ?? 50.0);
            $rb    = (float)($b['rsi'] ?? 50.0);
            if (abs($ra - $rb) > 0.001) {
                return ($sideA === 'long') ? ($ra < $rb ? -1 : 1) : ($ra > $rb ? -1 : 1);
            }

            // Тай-брейкер 3: алфавитный порядок symbol
            return strcmp((string)($a['symbol'] ?? ''), (string)($b['symbol'] ?? ''));
        });

        return $pool[0];
    }

    /**
     * Получить список стейблкоинов (хардкод + из settings).
     * Уточнения-7.
     */
    private function getStablecoinsBlacklist(): array
    {
        $list = self::DEFAULT_STABLECOINS;

        $fromSettings = Config::get('stablecoins_blacklist', null, null);
        if (is_array($fromSettings)) {
            foreach ($fromSettings as $s) {
                $coin = strtoupper(trim((string)$s));
                if ($coin !== '' && !in_array($coin, $list, true)) {
                    $list[] = $coin;
                }
            }
        }

        return $list;
    }

    /**
     * Запустить все защиты на намерение.
     *
     * @return array Список сработавших guards
     */
    private function runGuards(array $intent, array $context): array
    {
        $guards = [
            new MaxOpenPositionsGuard(),
            new MaxTotalOrdersGuard(),
            new SignalUpperCapGuard(),
            new DailyDrawdownGuard(),
            new LongShortBalanceGuard(),
        ];

        $fundingRate = null;
        // Загружаем funding если нужен
        $fundingEnabled = filter_var(Config::get('funding_filter.enabled', 's1', false), FILTER_VALIDATE_BOOLEAN);
        if ($fundingEnabled && isset($context['adapter'])) {
            try {
                $fr = $context['adapter']->getFundingRate((string)$intent['symbol']);
                $fundingRate = $fr['rate'] ?? null;
            } catch (\Throwable $e) {
                // Не блокируем из-за ошибки получения funding
                Logger::get()->warning('s1: getFundingRate failed', ['error' => $e->getMessage()]);
            }
        }

        $contextWithFunding = array_merge($context, ['funding_rate' => $fundingRate]);

        if ($fundingEnabled) {
            $guards[] = new FundingFilterGuard();
        }

        $results = [];
        foreach ($guards as $guard) {
            $triggered = $guard->check($intent, $contextWithFunding);
            $results   = array_merge($results, $triggered);
        }

        return $results;
    }

    /**
     * Рассчитать амплитуду за последние N свечей (§5.1).
     *
     * @param array $klines [{open, high, low, close, start}, ...]
     * @return float amplitude_pct
     */
    private function calcAmplitudePct(array $klines): float
    {
        if (empty($klines)) {
            return 5.0; // fallback
        }

        $maxHigh = PHP_FLOAT_MIN;
        $minLow  = PHP_FLOAT_MAX;

        foreach ($klines as $k) {
            $h = (float)($k['high'] ?? 0);
            $l = (float)($k['low']  ?? PHP_FLOAT_MAX);
            if ($h > $maxHigh) {
                $maxHigh = $h;
            }
            if ($l < $minLow) {
                $minLow = $l;
            }
        }

        if ($maxHigh <= 0) {
            return 5.0;
        }

        return ($maxHigh - $minLow) / $maxHigh * 100.0;
    }

    /**
     * Загрузить суммарный funding для сделки (из trade_funding_log).
     */
    private function loadFundingTotal(int $tradeId): float
    {
        $stmt = Database::pdo()->prepare(
            'SELECT SUM(amount_usdt) FROM trade_funding_log WHERE trade_id = :id'
        );
        $stmt->execute([':id' => $tradeId]);
        return (float)($stmt->fetchColumn() ?? 0.0);
    }

    private static function nowIso(): string
    {
        $t     = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\\TH:i:s.', (int)$t) . $micro . 'Z';
    }
}
