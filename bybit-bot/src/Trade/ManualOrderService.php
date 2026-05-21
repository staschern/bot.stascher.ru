<?php
declare(strict_types=1);

namespace BybitBot\Trade;

use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use BybitBot\Core\Rounding;
use BybitBot\Exchange\AdapterFactory;
use BybitBot\Exchange\ExchangeAdapter;

/**
 * ManualOrderService — обработка ручного ввода (Strategy 2).
 *
 * Пользователь вводит:
 *  - symbol  (например MNT или MNTUSDT)
 *  - side    (long|short)
 *  - entry   (trigger price условного ордера)
 *  - sl      (stop-loss price)
 *  - tp      (take-profit price)
 *
 * Сервис:
 *  1) нормализует symbol (UPPER + добавляет USDT если нет)
 *  2) проверяет символ через adapter->getInstrumentInfo() (бросает на 404)
 *  3) валидирует (long: tp>entry>sl, short: sl>entry>tp)
 *  4) считает leverage = max возможный (с учётом cap из настроек, как в s1)
 *  5) считает qty по той же формуле, что s1 (§5.4):
 *       p_used     = |TP - entry| / entry × 100
 *       base_lot   = 0.01 × deposit_anchor
 *       unleveraged= base_lot × 100 / p_used
 *       qty_usdt   = unleveraged / leverage
 *       qty_coins  = qty_usdt / entry
 *  6) создаёт запись в trades (strategy_id='s2'), ставит conditional ордер
 *  7) дальше сопровождение идёт по тем же модулям, что s1 — мы только подкладываем
 *     запись в trades со всеми полями, нужными для onPositionOpened/Averaged.
 *
 * См. spec.md §15 (v0.6.0).
 */
final class ManualOrderService
{
    /**
     * Нормализовать symbol: UPPER + добавить USDT если нет суффикса.
     */
    public static function normalizeSymbol(string $raw): string
    {
        $s = strtoupper(trim($raw));
        if ($s === '') {
            return '';
        }
        // Если уже заканчивается на распространённый quote — оставляем как есть.
        $quotes = ['USDT', 'USDC', 'USD', 'PERP'];
        foreach ($quotes as $q) {
            if (substr($s, -strlen($q)) === $q) {
                return $s;
            }
        }
        return $s . 'USDT';
    }

    /**
     * Проверить ввод (числа/знаки/направление).
     *
     * @return array Список ошибок (поле => сообщение). Пустой если ок.
     */
    public static function validateInput(array $input): array
    {
        $errors = [];

        $symbol = isset($input['symbol']) ? trim((string)$input['symbol']) : '';
        if ($symbol === '' || !preg_match('/^[A-Za-z0-9]{2,20}$/', $symbol)) {
            $errors['symbol'] = 'Symbol: только латиница и цифры, 2–20 символов';
        }

        $side = isset($input['side']) ? (string)$input['side'] : '';
        if (!in_array($side, ['long', 'short'], true)) {
            $errors['side'] = 'Side должен быть long или short';
        }

        foreach (['entry', 'sl', 'tp'] as $field) {
            if (!isset($input[$field]) || !is_numeric($input[$field])) {
                $errors[$field] = 'Должно быть числом';
                continue;
            }
            $val = (float)$input[$field];
            if ($val <= 0) {
                $errors[$field] = 'Должно быть > 0';
            }
        }

        if (!empty($errors)) {
            return $errors;
        }

        $entry = (float)$input['entry'];
        $sl    = (float)$input['sl'];
        $tp    = (float)$input['tp'];

        if ($side === 'long') {
            if (!($tp > $entry && $entry > $sl)) {
                $errors['_order'] = 'Для long требуется TP > Entry > SL';
            }
        } elseif ($side === 'short') {
            if (!($sl > $entry && $entry > $tp)) {
                $errors['_order'] = 'Для short требуется SL > Entry > TP';
            }
        }

        return $errors;
    }

    /**
     * Проверить, существует ли символ на бирже (через adapter).
     * Возвращает instrumentInfo если ок, либо null если не найден.
     *
     * v0.9.0-step4c: caller передаёт adapter (для multi-account фронта).
     * Если adapter=null — fallback на forCurrentMode() (paper/legacy).
     */
    public static function checkSymbol(string $symbol, ?ExchangeAdapter $adapter = null): ?array
    {
        $adapter = $adapter ?? AdapterFactory::forCurrentMode();
        try {
            return $adapter->getInstrumentInfo($symbol);
        } catch (\Throwable $e) {
            Logger::get()->info('manual: symbol check failed', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Полный путь: валидация → проверка символа → расчёт → INSERT trade → placeConditional.
     *
     * v0.9.0-step4c: открытие в контексте конкретного аккаунта.
     *   - $accountId/$accountName заданы (testnet/live) → AdapterFactory::forAccount($accountId),
     *     trades.account_id и trades.account_name заполняются.
     *   - null (paper/legacy) → AdapterFactory::forCurrentMode(), account_id=NULL.
     * Fan-out на несколько аккаунтов делает ManualController (вызывает submit() в цикле).
     *
     * @param array $input ['symbol','side','entry','sl','tp','ignore_sl_until_open']
     * @return array {ok:bool, trade_id?:int, errors?:array, message?:string, account_id?:?int, account_name?:?string}
     */
    public static function submit(array $input, ?int $accountId = null, ?string $accountName = null): array
    {
        // Шаг 1: валидация ввода
        $errors = self::validateInput($input);
        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors, 'message' => 'Ошибка валидации',
                    'account_id' => $accountId, 'account_name' => $accountName];
        }

        $symbol = self::normalizeSymbol((string)$input['symbol']);
        $side   = (string)$input['side'];      // long|short
        $entry  = (float)$input['entry'];
        $sl     = (float)$input['sl'];
        $tp     = (float)$input['tp'];

        // v0.9.0-step4c: выбор адаптера. paper или legacy — forCurrentMode(); иначе — per-account.
        try {
            $adapter = ($accountId === null)
                ? AdapterFactory::forCurrentMode()
                : AdapterFactory::forAccount($accountId);
        } catch (\Throwable $e) {
            return [
                'ok'           => false,
                'errors'       => ['_account' => 'Не удалось получить адаптер для аккаунта #' . (int)$accountId . ': ' . $e->getMessage()],
                'message'      => 'Ошибка адаптера аккаунта',
                'account_id'   => $accountId,
                'account_name' => $accountName,
            ];
        }
        $mode    = (string)Config::get('mode', null, 'paper');

        // Шаг 2: проверить символ на бирже
        $instrInfo = self::checkSymbol($symbol, $adapter);
        if ($instrInfo === null) {
            return [
                'ok'           => false,
                'errors'       => ['symbol' => "Символ {$symbol} не найден на бирже"],
                'message'      => "Символ {$symbol} не найден на бирже",
                'account_id'   => $accountId,
                'account_name' => $accountName,
            ];
        }

        $tickSize     = (float)$instrInfo['tickSize'];
        $qtyStep      = (float)$instrInfo['qtyStep'];
        $qtyMin       = (float)$instrInfo['qtyMin'];
        $maxLevBybit  = (float)$instrInfo['maxLeverage'];

        $isLong = ($side === 'long');

        // Шаг 3: округление цен к tickSize (как в s1)
        // Entry: для long округляем UP (как в s1 §5.2), для short — DOWN.
        if ($isLong) {
            $entryRef = Rounding::roundToStep($entry, $tickSize, Rounding::UP);
            $tpInit   = Rounding::roundToStep($tp,    $tickSize, Rounding::DOWN);
            $slInit   = Rounding::roundToStep($sl,    $tickSize, Rounding::UP);
        } else {
            $entryRef = Rounding::roundToStep($entry, $tickSize, Rounding::DOWN);
            $tpInit   = Rounding::roundToStep($tp,    $tickSize, Rounding::UP);
            $slInit   = Rounding::roundToStep($sl,    $tickSize, Rounding::DOWN);
        }

        if ($entryRef <= 0) {
            return ['ok' => false, 'errors' => ['entry' => 'Невалидная цена входа после округления'],
                    'message' => 'entry rounding failed',
                    'account_id' => $accountId, 'account_name' => $accountName];
        }

        // Шаг 4: реальный p_TP_used (после округления)
        $pTpUsed = abs(($tpInit - $entryRef) / $entryRef) * 100.0;
        if ($pTpUsed < 0.01) {
            return ['ok' => false, 'errors' => ['tp' => 'TP слишком близко к Entry после округления к tickSize'],
                    'message' => 'p too small',
                    'account_id' => $accountId, 'account_name' => $accountName];
        }

        // Шаг 5: leverage (та же логика, что в s1 §5.4)
        $leverageCapEnabled = filter_var(Config::get('leverage_cap.enabled', 's1', false), FILTER_VALIDATE_BOOLEAN);
        $leverageCapValue   = (int)Config::get('leverage_cap', 's1', 50);

        $maxLev = (int)floor($maxLevBybit);
        if ($leverageCapEnabled && $leverageCapValue > 0 && $leverageCapValue < $maxLev) {
            $maxLev = $leverageCapValue;
        } elseif (!$leverageCapEnabled) {
            $capDefault = (int)Config::get('leverage_cap', 's1', 50);
            if ($capDefault > 0 && $capDefault < $maxLev) {
                $maxLev = $capDefault;
            }
        }
        $leverage = max(1, $maxLev);

        // Шаг 6 (§5.4, v0.7.0): qty — плечо НЕ участвует в расчёте
        $depositAnchor    = self::loadDepositAnchor($mode);
        $baseLotUsdt      = 0.01 * $depositAnchor;
        $notionalUsdt     = $baseLotUsdt * 100.0 / $pTpUsed;
        $orderQtyCoinsRaw = $notionalUsdt / $entryRef;

        // safety-зазор
        $safetyPct = (float)Config::get('qty_safety_margin_pct', null, 10.0);
        if ($safetyPct < 0.0)  { $safetyPct = 0.0; }
        if ($safetyPct > 50.0) { $safetyPct = 50.0; }
        $orderQtyCoinsSafe = $orderQtyCoinsRaw * (1.0 - $safetyPct / 100.0);

        if ($orderQtyCoinsSafe < $qtyMin) {
            $orderQtyCoins = Rounding::roundToStep($qtyMin, $qtyStep, Rounding::UP);
        } else {
            $orderQtyCoins = Rounding::roundToStep($orderQtyCoinsSafe, $qtyStep, Rounding::DOWN);
        }

        // Алиасы для совместимости с последующим кодом
        $unleveragedUsdt = $notionalUsdt;
        $orderQtyUsdt    = $orderQtyCoins * $entryRef;

        // Шаг 7: создать запись в trades
        $marginMode = (string)Config::get('bybit_margin_mode', 's1', 'cross');
        $now        = self::nowIso();
        $pdo        = Database::pdo();

        // v0.8.0.13: флажок RECOVERED из формы s2
        $ignoreSlUntilOpen = !empty($input['ignore_sl_until_open']) ? 1 : 0;

        // v0.9.0-step4c: пишем account_id + account_name (NULL для paper).
        $stmt = $pdo->prepare(
            'INSERT INTO trades
             (mode, strategy_id, symbol, side, signal_target_pct, signal_id,
              status, entry_ref, leverage, margin_mode,
              tp_init, sl_init, sl_current, p_for_strategy_calc, created_at,
              ignore_sl_until_open, recovered_at,
              account_id, account_name)
             VALUES
             (:mode, :sid, :sym, :side, :tp_pct, NULL,
              :status, :entry, :lev, :mm,
              :tpinit, :slinit, :slcur, :p, :now,
              :islu, :recat,
              :acc, :accname)'
        );
        $stmt->execute([
            ':mode'    => $mode,
            ':sid'     => 's2',
            ':sym'     => $symbol,
            ':side'    => $side,
            ':tp_pct'  => $pTpUsed,
            ':status'  => 'PENDING_CONDITIONAL',
            ':entry'   => $entryRef,
            ':lev'     => $leverage,
            ':mm'      => $marginMode,
            ':tpinit'  => $tpInit,
            ':slinit'  => $slInit,
            ':slcur'   => $slInit,
            ':p'       => $pTpUsed,
            ':now'     => $now,
            ':islu'    => $ignoreSlUntilOpen,
            ':recat'   => $ignoreSlUntilOpen ? $now : null,
            ':acc'     => $accountId,
            ':accname' => $accountName,
        ]);
        $tradeId = (int)$pdo->lastInsertId();

        EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'manual_trade_created', [
            'symbol'       => $symbol,
            'side'         => $side,
            'entry'        => $entryRef,
            'sl'           => $slInit,
            'tp'           => $tpInit,
            'qty'          => $orderQtyCoins,
            'leverage'     => $leverage,
            'p_used'       => $pTpUsed,
            'account_id'   => $accountId,
            'account_name' => $accountName,
        ]);

        // Шаг 8: поставить conditional через adapter (тот же путь, что s1)
        // triggerDirection вычисляется адаптером автоматически (1=Rise если trigger>last, 2=Fall если trigger<last)
        $bybitSide = $isLong ? 'Buy' : 'Sell';
        $linkId    = "s2-{$tradeId}-entry-" . bin2hex(random_bytes(4));

        try {
            $adapter->setLeverage($symbol, $leverage);

            $orderId = $adapter->placeConditional([
                'trade_id'         => $tradeId,
                'strategy_id'      => 's2',
                'symbol'           => $symbol,
                'mode'             => $mode,
                'side'             => $bybitSide,
                'trade_side'       => $side,
                'order_type'       => 'Market',
                'qty'              => $orderQtyCoins,
                'trigger_price'    => $entryRef,
                'tp_price'         => $tpInit,
                'sl_price'         => $slInit,
                'order_link_id'    => $linkId,
                'leverage'         => $leverage,
                'margin_mode'      => $marginMode,
                'purpose'          => 'entry_conditional',
            ]);

            // Сохранить link_id и order_id в trade
            $pdo->prepare(
                'UPDATE trades SET order_link_id_open = :link, order_id_open = :oid WHERE id = :id'
            )->execute([':link' => $linkId, ':oid' => (string)$orderId, ':id' => $tradeId]);

            EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'conditional_placed', [
                'order_id'     => $orderId,
                'link_id'      => $linkId,
                'manual'       => true,
                'account_id'   => $accountId,
                'account_name' => $accountName,
            ]);

            Logger::get()->info('manual: conditional placed', [
                'trade_id'     => $tradeId,
                'symbol'       => $symbol,
                'order_id'     => $orderId,
                'account_id'   => $accountId,
                'account_name' => $accountName,
            ]);

            $accLabel = ($accountName !== null && $accountName !== '') ? " [{$accountName}]" : '';
            return [
                'ok'           => true,
                'trade_id'     => $tradeId,
                'message'      => "Trade #{$tradeId} создан, условный ордер выставлен{$accLabel}",
                'account_id'   => $accountId,
                'account_name' => $accountName,
            ];

        } catch (\Throwable $e) {
            // Откатываем — пометить trade как CANCELLED
            $pdo->prepare("UPDATE trades SET status = 'CANCELLED' WHERE id = :id")
                ->execute([':id' => $tradeId]);

            EventRecorder::tradeEvent($tradeId, EventRecorder::ERROR, 'manual_place_failed', [
                'error'        => $e->getMessage(),
                'account_id'   => $accountId,
                'account_name' => $accountName,
            ]);

            Logger::get()->error('manual: placeConditional failed', [
                'trade_id'     => $tradeId,
                'error'        => $e->getMessage(),
                'account_id'   => $accountId,
                'account_name' => $accountName,
            ]);

            $accLabel = ($accountName !== null && $accountName !== '') ? " [{$accountName}]" : '';
            return [
                'ok'           => false,
                'trade_id'     => $tradeId,
                'errors'       => ['_place' => $e->getMessage()],
                'message'      => 'Не удалось поставить ордер' . $accLabel . ': ' . $e->getMessage(),
                'account_id'   => $accountId,
                'account_name' => $accountName,
            ];
        }
    }

    /**
     * Получить deposit_anchor (копия из cron_hourly).
     */
    private static function loadDepositAnchor(string $mode): float
    {
        $stmt = Database::pdo()->prepare(
            "SELECT value FROM deposit_snapshots WHERE mode = :m ORDER BY ts DESC LIMIT 1"
        );
        $stmt->execute([':m' => $mode]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            return (float)$val;
        }
        if ($mode === 'paper') {
            return (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
        }
        return 300.0;
    }

    private static function nowIso(): string
    {
        $t     = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\\TH:i:s.', (int)$t) . $micro . 'Z';
    }
}
