#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * cron_hourly — часовой скрипт.
 *
 * Запускается в HH:01:00.
 *
 * Что делает (Stage 3):
 *  0. Reconciliation для testnet/live (сверка с реальной биржей).
 *  1. Импортирует свежие сигналы из signalsHourly.json в таблицу signals.
 *  2. Опционально пингует Bybit API.
 *  3. Если mode НЕ pause — для каждой включённой автоматической стратегии:
 *     - collectAutoIntents
 *     - для каждого intent ставит conditional ордер через адаптер
 *  Если mode = pause — новые сигналы пропускаются, существующие обслуживаются.
 *
 * Адаптер выбирается через AdapterFactory::forCurrentMode().
 *
 * См. spec.md §3.1 и §13a (v0.5.0).
 */

use BybitBot\Bybit\Client as BybitClient;
use BybitBot\Bybit\ServerTime;
use BybitBot\Core\Bootstrap;
use BybitBot\Core\Config;
use BybitBot\Core\CronGuard;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Lock;
use BybitBot\Core\Logger;
use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Exchange\AdapterFactory;
use BybitBot\Signals\Decisions;
use BybitBot\Exchange\BybitAdapter;
use BybitBot\Signals\Importer;
use BybitBot\Strategies\StrategyRegistry;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Bootstrap.php';
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

$lock = new Lock($root . '/data/locks/cron_hourly.lock');
if (!$lock->acquire()) {
    Logger::get()->info('cron_hourly: предыдущий запуск ещё работает — выходим.');
    exit(0);
}

$slot  = CronGuard::slotHourly();
$guard = new CronGuard('hourly', $slot);
if (!$guard->begin()) {
    Logger::get()->info("cron_hourly: запуск для слота {$slot} уже был — выходим.");
    exit(0);
}

try {
    EventRecorder::event(EventRecorder::INFO, 'cron_hourly_start', null, ['slot' => $slot]);

    $mode = (string)Config::get('mode', null, 'paper');

    // ───────────────────────────────────────────────────────
    // 0. Reconciliation (testnet/live)
    // ───────────────────────────────────────────────────────
    runReconciliation($mode);

    // ───────────────────────────────────────────────────────
    // 1. Импорт сигналов
    // ───────────────────────────────────────────────────────
    $summary = Importer::run();
    Logger::get()->info('cron_hourly: signals import done', $summary);

    // ───────────────────────────────────────────────────────
    // 2. Bybit health ping (опциональный)
    // ───────────────────────────────────────────────────────
    $pingEnabled = filter_var(
        Config::get('bybit_health_ping_enabled', null, false),
        FILTER_VALIDATE_BOOLEAN
    );
    $bybitPing = ['skipped' => true];
    if ($pingEnabled) {
        try {
            $client    = BybitClient::default();
            $bybitPing = ServerTime::ping($client);
        } catch (\Throwable $e) {
            $bybitPing = ['ok' => false, 'error' => $e->getMessage()];
            EventRecorder::event(EventRecorder::WARN, 'bybit_health_ping_exception', null, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ───────────────────────────────────────────────────────
    // 3. Стратегии: collectAutoIntents + placeConditional
    // ───────────────────────────────────────────────────────

    // Режим pause — НЕ обрабатывать новые сигналы
    if ($mode === 'pause') {
        Logger::get()->info('cron_hourly: mode=pause, пропуск collectAutoIntents');
        EventRecorder::event(EventRecorder::INFO, 'cron_hourly_pause_skip', null, ['mode' => 'pause']);

        $msg = sprintf(
            'mode=pause imported=%d ping=%s',
            (int)($summary['imported'] ?? 0),
            isset($bybitPing['skipped']) ? 'skipped' : (!empty($bybitPing['ok']) ? 'ok' : 'fail')
        );
        $guard->success($msg);
        return;
    }

    // ───────────────────────────────────────────────────────
    // v0.9.0-step4b: fan-out по аккаунтам.
    //   paper      → один проход, account_id=NULL (legacy).
    //   testnet/live → foreach по BybitAccountsRepo::getEnabledForNetwork($mode).
    //                  Если пусто — лог и выход без обработки сигналов
    //                  (открытые сделки disabled-аккаунтов всё равно сопровождаются
    //                  через cron_minute независимо).
    // ───────────────────────────────────────────────────────

    // v0.9.0-step7: в список targets попадают все enabled аккаунты (master switch).
    // Per-strategy фильтрация делается ниже внутри цикла $autoStrategies (по флагу s{N}_enabled),
    // чтобы не лишать аккаунт возможности участвовать в одних стратегиях и не участвовать в других.
    $accountTargets = []; // список [ ['id'=>?int,'name'=>?string,'flags'=>array], ... ]
    if ($mode === 'paper') {
        $accountTargets[] = ['id' => null, 'name' => null, 'flags' => ['s1' => true, 's2' => true, 's3' => true]];
    } else {
        $accs = BybitAccountsRepo::getEnabledForNetwork($mode);
        if (empty($accs)) {
            Logger::get()->info("cron_hourly: нет enabled аккаунтов для mode={$mode}, пропуск collectAutoIntents");
            EventRecorder::event(EventRecorder::INFO, 'cron_hourly_no_enabled_accounts', null, ['mode' => $mode]);
        }
        foreach ($accs as $a) {
            $accountTargets[] = [
                'id'    => (int)$a['id'],
                'name'  => (string)$a['name'],
                'flags' => [
                    's1' => (bool)($a['s1_enabled'] ?? true),
                    's2' => (bool)($a['s2_enabled'] ?? true),
                    's3' => (bool)($a['s3_enabled'] ?? true),
                ],
            ];
        }
    }

    // Реестр стратегий — один раз для всех аккаунтов.
    $strategiesConfig = require $root . '/config/strategies.php';
    $registry         = new StrategyRegistry($strategiesConfig);
    $autoStrategies   = $registry->enabledAutomatic();

    // Получить текущий deposit_anchor (общий для режима — пока без per-account якоря).
    $depositAnchor = loadDepositAnchor($mode);

    $totalIntents  = 0;
    $totalPlaced   = 0;
    $totalSkipped  = 0;
    $accountsRun   = 0;

    foreach ($accountTargets as $target) {
        $accId    = $target['id'];
        $accName  = $target['name'];
        $accFlags = (array)($target['flags'] ?? ['s1' => true, 's2' => true, 's3' => true]);
        $accountsRun++;

        $logCtx = ['account_id' => $accId, 'account_name' => $accName, 'mode' => $mode];

        // Выбор адаптера: paper → forCurrentMode(); testnet/live → forAccount($accId).
        try {
            if ($accId === null) {
                $adapter = AdapterFactory::forCurrentMode();
            } else {
                $adapter = AdapterFactory::forAccount($accId);
            }
        } catch (\Throwable $e) {
            Logger::get()->error('cron_hourly: не удалось создать адаптер для аккаунта', array_merge($logCtx, ['error' => $e->getMessage()]));
            EventRecorder::event(EventRecorder::ERROR, 'hourly_adapter_init_failed', null, array_merge($logCtx, ['error' => $e->getMessage()]));
            continue;
        }

        // Получить открытые позиции и ордера (для guards) — per-account.
        $positions  = [];
        $openOrders = [];
        try {
            $positions  = $adapter->getPositions();
            $openOrders = $adapter->getOpenOrders();
        } catch (\Throwable $e) {
            Logger::get()->warning('cron_hourly: не удалось загрузить positions/orders', array_merge($logCtx, ['error' => $e->getMessage()]));
        }

        // v0.9.0-step7 §3: подсчёт НАШИХ открытых trades по этому аккаунту и mode —
        // используется в MaxTotalOrdersGuard. Это отвязывает гард от чужих ордеров/позиций,
        // которые Bybit V5 UNIFIED может возвращать по ключам разных субаккаунтов.
        $ourOpenCount    = 0;
        $ourPendingCount = 0;
        try {
            $sql = "SELECT status, COUNT(*) AS cnt
                      FROM trades
                     WHERE mode = :m
                       AND status IN ('OPEN','AVERAGED','PENDING_CONDITIONAL')";
            $bind = [':m' => $mode];
            if ($accId === null) {
                $sql .= " AND account_id IS NULL";
            } else {
                $sql .= " AND account_id = :acc";
                $bind[':acc'] = $accId;
            }
            $sql .= " GROUP BY status";
            $stmt = \BybitBot\Core\Database::pdo()->prepare($sql);
            $stmt->execute($bind);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $cnt = (int)$r['cnt'];
                if ($r['status'] === 'PENDING_CONDITIONAL') $ourPendingCount += $cnt;
                else                                       $ourOpenCount    += $cnt;
            }
        } catch (\Throwable $e) {
            Logger::get()->warning('cron_hourly: подсчёт our_trades failed', array_merge($logCtx, ['error' => $e->getMessage()]));
        }

        $context = [
            'adapter'         => $adapter,
            'deposit_anchor'  => $depositAnchor,
            'current_balance' => $depositAnchor,
            'positions'       => $positions,
            'open_orders'     => $openOrders,
            'mode'            => $mode,
            'account_id'      => $accId,
            'account_name'    => $accName,
            // v0.9.0-step7 §3: для MaxTotalOrdersGuard.
            'our_trades_open_count'    => $ourOpenCount,
            'our_trades_pending_count' => $ourPendingCount,
        ];

        Logger::get()->info('cron_hourly: account fan-out start', $logCtx);

        foreach ($autoStrategies as $stratId => $strategy) {
            // v0.9.0-step7: респектируем per-strategy флаг аккаунта. Если аккаунт выключен для
            // этой стратегии — пропускаем (сопровождение уже открытых trades не трогаем — оно в cron_minute).
            if (!(bool)($accFlags[$stratId] ?? true)) {
                Logger::get()->info("cron_hourly: аккаунт #{$accId} выключен для стратегии {$stratId}, пропуск", $logCtx);
                EventRecorder::event(EventRecorder::INFO, 'fanout_skip_strategy_disabled', null, array_merge($logCtx, [
                    'strategy_id' => $stratId,
                ]));
                continue;
            }

            Logger::get()->info("cron_hourly: запуск стратегии {$stratId}", $logCtx);

            try {
                $intents = $strategy->collectAutoIntents($context);
            } catch (\Throwable $e) {
                Logger::get()->error("cron_hourly: collectAutoIntents failed для {$stratId}", array_merge($logCtx, [
                    'error' => $e->getMessage(),
                ]));
                EventRecorder::event(EventRecorder::ERROR, 'strategy_collect_failed', null, array_merge($logCtx, [
                    'strategy_id' => $stratId,
                    'error'       => $e->getMessage(),
                ]));
                continue;
            }

            $totalIntents += count($intents);

            // v0.8.0.14: fallback-цепочка. Для s1 collectAutoIntents возвращает
            // список отранжированных intents. Пробуем по приоритету, при фейле placeConditional
            // (кроме insufficient_balance) — переходим к следующему. Лимит — 3 попытки.
            // v0.9.0-step4b: счётчики попыток и placedInChain — per-account-per-strategy
            // (т.к. цикл per-account → один вход/час/аккаунт согласно §4.1).
            $maxAttempts    = 3;
            $attemptCount   = 0;
            $placedInChain  = false;
            $lastAttemptErr = null;

            foreach ($intents as $intent) {
                if ($placedInChain) {
                    break;
                }
                if ($attemptCount >= $maxAttempts) {
                    EventRecorder::event(EventRecorder::WARN, 'hourly_fallback_exhausted', null, array_merge($logCtx, [
                        'strategy_id'   => $stratId,
                        'max_attempts'  => $maxAttempts,
                        'last_error'    => $lastAttemptErr,
                    ]));
                    Logger::get()->warning('cron_hourly: исчерпан лимит fallback', array_merge($logCtx, [
                        'strategy_id'  => $stratId,
                        'attempts'     => $attemptCount,
                        'last_error'   => $lastAttemptErr,
                    ]));
                    break;
                }

                $symbol   = (string)($intent['symbol'] ?? '');
                $signalId = (int)($intent['signal_id'] ?? 0);
                $rank     = (int)($intent['rank'] ?? ($attemptCount + 1));

                // Идемпотентность per-(signal_id, account_id).
                if ($signalId > 0 && tradeExistsForSignal($signalId, $accId)) {
                    Logger::get()->info("cron_hourly: trade уже существует для signal_id={$signalId}, пропуск", $logCtx);
                    Decisions::record($signalId, Decisions::REJECTED_DUPLICATE, 'trade уже существует для этого signal_id/account');
                    $totalSkipped++;
                    continue;
                }

                // Замена conditional по тому же (symbol, side) per-account (§3.1 шаг 7).
                $tradeSide = (string)($intent['trade_side'] ?? $intent['side'] ?? 'long');
                cancelOldConditionalForSymbol($symbol, $tradeSide, $mode, $adapter, $accId);

                // Создаём запись в trades с account_id + account_name.
                $tradeId = createTradeRecord($intent, $mode, $accId, $accName);
                if ($tradeId === null) {
                    Logger::get()->warning("cron_hourly: не удалось создать trade для {$symbol}", $logCtx);
                    Decisions::record($signalId, Decisions::REJECTED_OTHER, 'createTradeRecord failed');
                    $totalSkipped++;
                    continue;
                }
                Decisions::record($signalId, Decisions::ACCEPTED, 'trade создан', $tradeId);

                $intent['tmp_trade_id']  = $tradeId;
                // tradeId глобально уникален → order_link_id уникален per-account.
                $intent['order_link_id'] = "s1-{$tradeId}-entry-" . bin2hex(random_bytes(4));

                $attemptCount++;
                EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'conditional_placing', [
                    'symbol'        => $symbol,
                    'side'          => $intent['side'],
                    'trigger_price' => $intent['trigger_price'],
                    'qty'           => $intent['qty'],
                    'tp'            => $intent['tp_price'] ?? null,
                    'sl'            => $intent['sl_price'] ?? null,
                    'leverage'      => $intent['leverage'],
                    'attempt'       => $attemptCount,
                    'rank'          => $rank,
                    'account_id'    => $accId,
                    'account_name'  => $accName,
                ]);

                try {
                    $adapter->setLeverage($symbol, (int)$intent['leverage']);
                    $orderId = $adapter->placeConditional(array_merge($intent, ['trade_id' => $tradeId]));

                    updateTradeAfterPlace($tradeId, (string)$intent['order_link_id'], (string)$orderId);

                    EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'conditional_placed', [
                        'order_id'     => $orderId,
                        'link_id'      => $intent['order_link_id'],
                        'attempt'      => $attemptCount,
                        'rank'         => $rank,
                        'account_id'   => $accId,
                        'account_name' => $accName,
                    ]);
                    Logger::get()->info("cron_hourly: conditional поставлен", array_merge($logCtx, [
                        'trade_id' => $tradeId,
                        'symbol'   => $symbol,
                        'order_id' => $orderId,
                        'attempt'  => $attemptCount,
                        'rank'     => $rank,
                    ]));
                    $totalPlaced++;
                    $placedInChain = true;
                } catch (\Throwable $e) {
                    $errMsg = $e->getMessage();
                    $lastAttemptErr = $errMsg;
                    Logger::get()->error("cron_hourly: placeConditional failed", array_merge($logCtx, [
                        'trade_id' => $tradeId,
                        'symbol'   => $symbol,
                        'attempt'  => $attemptCount,
                        'rank'     => $rank,
                        'error'    => $errMsg,
                    ]));
                    EventRecorder::tradeEvent($tradeId, EventRecorder::ERROR, 'conditional_place_failed', [
                        'error'        => $errMsg,
                        'attempt'      => $attemptCount,
                        'rank'         => $rank,
                        'account_id'   => $accId,
                        'account_name' => $accName,
                    ]);
                    cancelTradeRecord($tradeId, $errMsg);
                    $totalSkipped++;

                    if (isInsufficientBalanceError($errMsg)) {
                        EventRecorder::event(EventRecorder::WARN, 'hourly_fallback_stopped_balance', null, array_merge($logCtx, [
                            'strategy_id' => $stratId,
                            'symbol'      => $symbol,
                            'attempt'     => $attemptCount,
                        ]));
                        Logger::get()->warning('cron_hourly: остановка fallback — insufficient balance', array_merge($logCtx, [
                            'strategy_id' => $stratId,
                            'symbol'      => $symbol,
                        ]));
                        break;
                    }

                    EventRecorder::event(EventRecorder::INFO, 'signal_fallback_attempt', null, array_merge($logCtx, [
                        'strategy_id'   => $stratId,
                        'failed_symbol' => $symbol,
                        'failed_error'  => $errMsg,
                        'attempt'       => $attemptCount,
                        'max_attempts'  => $maxAttempts,
                    ]));
                }
            }
        }
    }

    $msg = sprintf(
        'imported=%d skipped=%d failed=%d ping=%s strategies=%d accounts=%d intents=%d placed=%d mode=%s',
        (int)($summary['imported']  ?? 0),
        (int)($summary['skipped']   ?? 0),
        (int)($summary['failed']    ?? 0),
        isset($bybitPing['skipped']) ? 'skipped' : (!empty($bybitPing['ok']) ? 'ok' : 'fail'),
        count($autoStrategies),
        $accountsRun,
        $totalIntents,
        $totalPlaced,
        $mode
    );
    $guard->success($msg);

} catch (\Throwable $e) {
    Logger::get()->error('cron_hourly fatal: ' . $e->getMessage(), ['exception' => (string)$e]);
    EventRecorder::event(EventRecorder::CRITICAL, 'cron_hourly_fatal', null, ['error' => $e->getMessage()]);
    $guard->fail($e->getMessage());
    exit(1);
} finally {
    $lock->release();
}

// ────────────────────────────────────────────────────────────

/**
 * Reconciliation: сверка локальных данных с реальной биржей (testnet/live).
 * Не блокирует hourly — только логирует расхождения.
 */
function runReconciliation(string $mode): void
{
    if ($mode === 'paper' || $mode === 'pause') {
        return;
    }

    $pdo            = Database::pdo();
    $activeExchanges = [];

    if ($mode === 'testnet' || $mode === 'live') {
        $activeExchanges[] = $mode;
    }

    // Также проверяем другие активные exchange из БД
    $stmt = $pdo->query(
        "SELECT DISTINCT exchange FROM orders
         WHERE status IN ('placed','pending') AND exchange IN ('testnet','live')"
    );
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $exch) {
        if (!in_array($exch, $activeExchanges, true)) {
            $activeExchanges[] = $exch;
        }
    }

    // v0.9.0-step8: per-account реконсайл.
    // Раньше cron_hourly создавал BybitAdapter без accountId → ходил под default-ключом
    // (обычно акк #1), но локальную выборку делал по всем account_id → чужие ордера
    // ошибочно помечал cancelled (root cause phantom-cancellation avg-ордеров на акк #2).
    // Теперь бежим по каждому enabled-аккаунту с правильным API-ключом и SQL-фильтром
    // по account_id. Также явно вытаскиваем conditional через orderFilter=StopOrder
    // (LiveReconciler в cron_minute обновлён аналогично).
    foreach ($activeExchanges as $exchange) {
        $accounts = BybitAccountsRepo::getEnabledForNetwork($exchange);
        if (empty($accounts)) {
            // Legacy fallback: нет записей в bybit_accounts — работаем без accountId.
            $accounts = [['id' => null, 'name' => 'legacy']];
        }

        foreach ($accounts as $acc) {
            $accountId   = isset($acc['id']) ? $acc['id'] : null;
            $accountName = (string)($acc['name'] ?? 'legacy');

            try {
                $adapter = $accountId !== null
                    ? new BybitAdapter($exchange, (int)$accountId)
                    : new BybitAdapter($exchange);

                // Remote orders: обычные + conditional (StopOrder).
                $remoteOrders = $adapter->getOpenOrders();
                $remoteByLinkId = [];
                foreach ($remoteOrders as $ro) {
                    $lid = (string)($ro['orderLinkId'] ?? '');
                    if ($lid !== '') {
                        $remoteByLinkId[$lid] = $ro;
                    }
                }

                // Local orders — только этого аккаунта.
                if ($accountId !== null) {
                    $localStmt = $pdo->prepare(
                        "SELECT o.id, o.bybit_order_link_id, o.bybit_order_id, o.trade_id, o.purpose
                         FROM orders o
                         WHERE o.exchange = :exch AND o.status = 'placed' AND o.account_id = :acc"
                    );
                    $localStmt->execute([':exch' => $exchange, ':acc' => (int)$accountId]);
                } else {
                    $localStmt = $pdo->prepare(
                        "SELECT o.id, o.bybit_order_link_id, o.bybit_order_id, o.trade_id, o.purpose
                         FROM orders o
                         WHERE o.exchange = :exch AND o.status = 'placed' AND o.account_id IS NULL"
                    );
                    $localStmt->execute([':exch' => $exchange]);
                }
                $localOrders = $localStmt->fetchAll();

                $now = gmdate('Y-m-d\\TH:i:s.v\\Z');

                foreach ($localOrders as $lo) {
                    $lid = (string)($lo['bybit_order_link_id'] ?? '');
                    if ($lid === '' || isset($remoteByLinkId[$lid])) {
                        continue;
                    }
                    // ВАЖНО: cron_hourly больше НЕ помечает cancelled сам — это компетенция
                    // LiveReconciler (cron_minute), который проверит executions/list перед
                    // тем как пометить cancelled vs filled. Здесь только WARN-событие
                    // для аудита, чтобы было видно расхождение.
                    EventRecorder::tradeEvent(
                        (int)$lo['trade_id'],
                        EventRecorder::WARN,
                        'reconcile_missing_remote_order',
                        [
                            'order_link_id' => $lid,
                            'exchange'      => $exchange,
                            'purpose'       => (string)($lo['purpose'] ?? ''),
                            'account_id'    => $accountId,
                            'account_name'  => $accountName,
                        ]
                    );
                }

                // Remote positions для этого аккаунта.
                $remotePositions = $adapter->getPositions();
                $remoteSymbols   = [];
                foreach ($remotePositions as $rp) {
                    $sym = (string)($rp['symbol'] ?? '');
                    $sz  = (float)($rp['size'] ?? 0);
                    if ($sym !== '' && $sz > 0) {
                        $remoteSymbols[$sym] = true;
                    }
                }

                // Local positions — только этого аккаунта.
                if ($accountId !== null) {
                    $localPosStmt = $pdo->prepare(
                        "SELECT p.id, p.trade_id, t.symbol
                         FROM positions p JOIN trades t ON t.id = p.trade_id
                         WHERE p.exchange = :exch AND p.closed_at IS NULL AND p.account_id = :acc"
                    );
                    $localPosStmt->execute([':exch' => $exchange, ':acc' => (int)$accountId]);
                } else {
                    $localPosStmt = $pdo->prepare(
                        "SELECT p.id, p.trade_id, t.symbol
                         FROM positions p JOIN trades t ON t.id = p.trade_id
                         WHERE p.exchange = :exch AND p.closed_at IS NULL AND p.account_id IS NULL"
                    );
                    $localPosStmt->execute([':exch' => $exchange]);
                }
                $localPositions = $localPosStmt->fetchAll();

                foreach ($localPositions as $lp) {
                    $sym = (string)$lp['symbol'];
                    if (!isset($remoteSymbols[$sym])) {
                        EventRecorder::tradeEvent(
                            (int)$lp['trade_id'],
                            EventRecorder::WARN,
                            'reconcile_missing_remote_position',
                            [
                                'symbol'       => $sym,
                                'exchange'     => $exchange,
                                'account_id'   => $accountId,
                                'account_name' => $accountName,
                            ]
                        );
                    }
                }

                Logger::get()->info("cron_hourly: reconciliation done", [
                    'exchange'        => $exchange,
                    'account_id'      => $accountId,
                    'account_name'    => $accountName,
                    'remote_orders'   => count($remoteOrders),
                    'remote_positions'=> count($remotePositions),
                    'local_orders'    => count($localOrders),
                    'local_positions' => count($localPositions),
                ]);

            } catch (\Throwable $e) {
                Logger::get()->warning("cron_hourly: reconciliation failed for {$exchange} acc={$accountName}", [
                    'error' => $e->getMessage(),
                ]);
                EventRecorder::event(EventRecorder::WARN, 'reconcile_failed', null, [
                    'exchange'   => $exchange,
                    'account_id' => $accountId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}

/**
 * Получить deposit_anchor для текущего режима.
 */
function loadDepositAnchor(string $mode): float
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

/**
 * Проверить, есть ли уже PENDING_CONDITIONAL trade с таким signal_id.
 */
/**
 * v0.9.0-step4b: идемпотентность расширена до (signal_id, account_id).
 * При multi-account каждый аккаунт должен получить свою сделку по одному сигналу.
 * Для legacy/paper accountId = null — проверяем по (signal_id, account_id IS NULL).
 */
function tradeExistsForSignal(int $signalId, ?int $accountId = null): bool
{
    if ($accountId === null) {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM trades
             WHERE signal_id = :sid
               AND status = 'PENDING_CONDITIONAL'
               AND account_id IS NULL"
        );
        $stmt->execute([':sid' => $signalId]);
    } else {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM trades
             WHERE signal_id = :sid
               AND status = 'PENDING_CONDITIONAL'
               AND account_id = :acc"
        );
        $stmt->execute([':sid' => $signalId, ':acc' => $accountId]);
    }
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Отменить старые PENDING_CONDITIONAL по (symbol, side, mode).
 *
 * v0.8.0.6: фильтр расширен. Раньше отменяли только conditional той же стратегии,
 * из-за чего две разные стратегии могли держать 2 conditional на (AERO, short).
 * Теперь — не более одного PENDING_CONDITIONAL на (symbol, side, mode),
 * независимо от strategy_id. Side нормализуется (long/short ↔ Buy/Sell).
 */
function cancelOldConditionalForSymbol(string $symbol, string $side, string $mode, $adapter, ?int $accountId = null): void
{
    // Нормализуем side к набору вариантов, который может быть в БД.
    $sideLower = strtolower($side);
    if ($sideLower === 'buy')  { $sideLower = 'long'; }
    if ($sideLower === 'sell') { $sideLower = 'short'; }
    $sideVariants = [$sideLower, ucfirst($sideLower)];
    if ($sideLower === 'long')  { $sideVariants[] = 'Buy';  $sideVariants[] = 'buy'; }
    if ($sideLower === 'short') { $sideVariants[] = 'Sell'; $sideVariants[] = 'sell'; }
    $sideVariants = array_values(array_unique($sideVariants));

    $placeholders = [];
    $params = [':sym' => $symbol, ':mode' => $mode];
    foreach ($sideVariants as $i => $v) {
        $key = ":side{$i}";
        $placeholders[] = $key;
        $params[$key] = $v;
    }
    $inSql = implode(',', $placeholders);

    // v0.9.0-step4b: в multi-account отменяем только свои PENDING по этому (symbol,side).
    $accFilter = '';
    if ($accountId === null) {
        $accFilter = ' AND account_id IS NULL';
    } else {
        $accFilter = ' AND account_id = :acc';
        $params[':acc'] = $accountId;
    }

    $stmt = Database::pdo()->prepare(
        "SELECT id, order_link_id_open, strategy_id FROM trades
         WHERE symbol = :sym
           AND mode = :mode
           AND side IN ({$inSql})
           AND status = 'PENDING_CONDITIONAL'" . $accFilter . "
         ORDER BY created_at ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return;
    }

    foreach ($rows as $old) {
        $oldTradeId = (int)$old['id'];
        $oldLinkId  = (string)($old['order_link_id_open'] ?? '');
        $oldStratId = (string)($old['strategy_id'] ?? '');

        if ($oldLinkId !== '') {
            try {
                $adapter->cancelOrder($oldLinkId);
            } catch (\Throwable $e) {
                Logger::get()->warning("cron_hourly: не удалось отменить старый conditional", [
                    'trade_id' => $oldTradeId,
                    'link_id'  => $oldLinkId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        Database::pdo()->prepare(
            "UPDATE trades SET status = 'CANCELLED', closed_at = :now WHERE id = :id"
        )->execute([':now' => gmdate('Y-m-d\\TH:i:s.v\\Z'), ':id' => $oldTradeId]);

        EventRecorder::tradeEvent($oldTradeId, EventRecorder::INFO, 'conditional_replaced', [
            'symbol'      => $symbol,
            'side'        => $sideLower,
            'mode'        => $mode,
            'strategy_id' => $oldStratId,
        ]);
    }
}

/**
 * Создать запись в trades и вернуть trade_id.
 */
function createTradeRecord(array $intent, string $mode, ?int $accountId = null, ?string $accountName = null): ?int
{
    $pdo = Database::pdo();
    $now = gmdate('Y-m-d\\TH:i:s.v\\Z');

    // v0.9.0-step4b: сохраняем account_id + account_name (денормализованный снимок).
    $stmt = $pdo->prepare(
        'INSERT INTO trades
         (mode, strategy_id, symbol, side, signal_target_pct, signal_w7, signal_rsi,
          signal_payload_json, signal_id, status, entry_ref, leverage, margin_mode,
          tp_init, sl_init, sl_current, p_for_strategy_calc, created_at,
          account_id, account_name)
         VALUES
         (:mode, :sid, :sym, :side, :tp, :w7, :rsi,
          :payload, :signalid, :status, :entry, :lev, :mm,
          :tpinit, :slinit, :slcur, :p, :now,
          :acc, :accname)'
    );

    $ok = $stmt->execute([
        ':mode'    => $mode,
        ':sid'     => (string)($intent['strategy_id'] ?? 's1'),
        ':sym'     => (string)($intent['symbol'] ?? ''),
        ':side'    => (string)($intent['trade_side'] ?? $intent['side'] ?? 'long'),
        ':tp'      => isset($intent['signal_target_pct']) ? (float)$intent['signal_target_pct'] : null,
        ':w7'      => isset($intent['signal_w7'])  ? (int)$intent['signal_w7']   : null,
        ':rsi'     => isset($intent['signal_rsi']) ? (float)$intent['signal_rsi'] : null,
        ':payload' => null,
        ':signalid'=> isset($intent['signal_id']) ? (int)$intent['signal_id'] : null,
        ':status'  => 'PENDING_CONDITIONAL',
        ':entry'   => isset($intent['entry_ref']) ? (float)$intent['entry_ref'] : null,
        ':lev'     => isset($intent['leverage'])  ? (int)$intent['leverage']    : null,
        ':mm'      => (string)($intent['margin_mode'] ?? 'cross'),
        ':tpinit'  => isset($intent['tp_price']) ? (float)$intent['tp_price'] : null,
        ':slinit'  => isset($intent['sl_price']) ? (float)$intent['sl_price'] : null,
        ':slcur'   => isset($intent['sl_price']) ? (float)$intent['sl_price'] : null,
        ':p'       => isset($intent['p_for_strategy_calc']) ? (float)$intent['p_for_strategy_calc'] : null,
        ':now'     => $now,
        ':acc'     => $accountId,
        ':accname' => $accountName,
    ]);

    if (!$ok) {
        return null;
    }

    return (int)$pdo->lastInsertId();
}

/**
 * Обновить trades после успешной постановки conditional.
 */
function updateTradeAfterPlace(int $tradeId, string $linkId, string $orderId): void
{
    Database::pdo()->prepare(
        'UPDATE trades SET order_link_id_open = :link, order_id_open = :oid WHERE id = :id'
    )->execute([':link' => $linkId, ':oid' => $orderId, ':id' => $tradeId]);
}

/**
 * Пометить trade как CANCELLED если постановка не удалась.
 */
function cancelTradeRecord(int $tradeId, string $reason): void
{
    Database::pdo()->prepare(
        "UPDATE trades SET status = 'CANCELLED', closed_at = :now WHERE id = :id"
    )->execute([':now' => gmdate('Y-m-d\\TH:i:s.v\\Z'), ':id' => $tradeId]);

    EventRecorder::tradeEvent($tradeId, EventRecorder::ERROR, 'trade_cancelled_on_place_fail', [
        'reason' => $reason,
    ]);
}

/**
 * v0.8.0.14: определить, является ли ошибка "insufficient balance".
 * Bybit V5: retCode 110007/110012/110014/110045, или текст 'insufficient'.
 * Такая ошибка НЕ ретраится — при нехватке баланса у большего сигнала у следующих тоже не хватит (и подавно объём не меньше).
 */
function isInsufficientBalanceError(string $errMsg): bool
{
    $low = strtolower($errMsg);
    if (strpos($low, 'insufficient') !== false) {
        return true;
    }
    // Коды Bybit V5 (на случай если они попадут в errMsg):
    if (preg_match('/\b(110007|110012|110014|110045|10004)\b/', $errMsg)) {
        return true;
    }
    // 'ab not enough', 'balance not enough'
    if (strpos($low, 'balance not enough') !== false) {
        return true;
    }
    if (strpos($low, 'available balance') !== false && strpos($low, 'not enough') !== false) {
        return true;
    }
    return false;
}
