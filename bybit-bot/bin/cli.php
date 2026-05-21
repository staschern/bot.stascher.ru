#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI-утилиты Bybit Bot.
 *
 * Команды:
 *   php bin/cli.php migrate                — применить миграции и засеять дефолты
 *   php bin/cli.php auth:init              — создать пользователя UI с TOTP
 *   php bin/cli.php strategies:list        — показать стратегии и их статус
 *   php bin/cli.php settings:show          — показать все настройки из БД
 *
 * Этап 2:
 *   php bin/cli.php bybit:server-time      — пинг публичного API (без подписи)
 *   php bin/cli.php bybit:auth-check       — проверка приватного API (с подписью)
 *   php bin/cli.php bybit:refresh-instruments — обновить кеш инструментов Bybit
 *   php bin/cli.php signals:import         — ручной запуск импорта сигналов
 *   php bin/cli.php signals:resolve        — перерезолвить unresolved сигналы
 *   php bin/cli.php signals:show [N]       — показать последние N сигналов (по умолчанию 20)
 *   php bin/cli.php signals:unresolved     — показать сигналы без сопоставления с Bybit
 *   php bin/cli.php symbols:alias <src> <bybit> [note]
 *                                          — добавить ручной алиас
 *
 * См. spec.md §2.1.
 */

use BybitBot\Bybit\Client as BybitClient;
use BybitBot\Bybit\Errors;
use BybitBot\Bybit\MarketInfo;
use BybitBot\Bybit\ServerTime;
use BybitBot\Core\Bootstrap;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use BybitBot\Core\Migrator;
use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Exchange\AdapterFactory;
use BybitBot\Exchange\BybitAdapter;
use BybitBot\Exchange\PaperAdapter;
use BybitBot\Signals\Importer;
use BybitBot\Strategies\StrategyRegistry;
use BybitBot\Web\Auth;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Bootstrap.php';
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'migrate':
        cmdMigrate($root);
        break;

    case 'auth:init':
        cmdAuthInit();
        break;

    case 'strategies:list':
        cmdStrategiesList($root);
        break;

    case 'settings:show':
        cmdSettingsShow();
        break;

    case 'bybit:server-time':
        cmdBybitServerTime();
        break;

    case 'bybit:auth-check':
        cmdBybitAuthCheck();
        break;

    case 'bybit:refresh-instruments':
        cmdBybitRefreshInstruments();
        break;

    case 'signals:import':
        cmdSignalsImport();
        break;

    case 'signals:resolve':
        cmdSignalsResolve();
        break;

    case 'signals:show':
        cmdSignalsShow((int)($argv[2] ?? 20));
        break;

    case 'signals:unresolved':
        cmdSignalsUnresolved();
        break;

    case 'symbols:alias':
        cmdSymbolsAlias($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? null);
        break;

    // ── Stage 2 item 3: Strategy 1 / paper ──────────────────
    case 'strategy:s1:scan':
        $dryRun = in_array('--dry-run', $argv, true);
        cmdStrategyS1Scan($root, $dryRun);
        break;

    case 'strategy:s1:run':
        cmdStrategyS1Run($root);
        break;

    case 'bybit:wallet':
        cmdBybitWallet();
        break;

    case 'bybit:positions':
        cmdBybitPositions();
        break;

    case 'deposit:refresh':
        cmdDepositRefresh();
        break;

    case 'paper:reset':
        cmdPaperReset();
        break;

    case 'trades:show':
        cmdTradesShow((int)($argv[2] ?? 20));
        break;

    case 'orders:show':
        cmdOrdersShow(isset($argv[2]) ? (int)$argv[2] : null);
        break;

    // ── Stage 3: режим, paper, reconcile, exchange:status ───
    case 'bybit:mode':
        $sub = $argv[2] ?? '';
        if ($sub === 'get') {
            cmdBybitModeGet();
        } elseif ($sub === 'set') {
            $newMode = $argv[3] ?? '';
            $confirm = in_array('--confirm', $argv, true);
            cmdBybitModeSet($newMode, $confirm);
        } else {
            fwrite(STDERR, "Использование: bybit:mode get | bybit:mode set <mode> [--confirm]\n");
            exit(1);
        }
        break;

    case 'paper:close-all':
        cmdPaperCloseAll();
        break;

    case 'reconcile:now':
        $exchArg = $argv[2] ?? null;
        cmdReconcileNow($exchArg);
        break;

    case 'exchange:status':
        cmdExchangeStatus();
        break;

    default:
        echo "Bybit Futures Bot — CLI\n\n";
        echo "Базовые команды:\n";
        echo "  php bin/cli.php migrate                — применить миграции\n";
        echo "  php bin/cli.php auth:init              — создать пользователя UI\n";
        echo "  php bin/cli.php strategies:list        — список стратегий\n";
        echo "  php bin/cli.php settings:show          — все настройки из БД\n\n";
        echo "Bybit (Этап 2):\n";
        echo "  php bin/cli.php bybit:server-time            — пинг public API\n";
        echo "  php bin/cli.php bybit:auth-check             — проверить подпись\n";
        echo "  php bin/cli.php bybit:refresh-instruments    — обновить кеш инструментов\n\n";
        echo "Сигналы:\n";
        echo "  php bin/cli.php signals:import         — ручной импорт\n";
        echo "  php bin/cli.php signals:resolve        — перерезолвить unresolved\n";
        echo "  php bin/cli.php signals:show [N]       — последние N сигналов\n";
        echo "  php bin/cli.php signals:unresolved     — несопоставленные\n";
        echo "  php bin/cli.php symbols:alias <src> <bybit> [note]\n";
        echo "                                          — ручной алиас тикера\n\n";
        echo "Strategy 1 / Paper (Этап 2 item 3):\n";
        echo "  php bin/cli.php strategy:s1:scan [--dry-run] — выбор сигнала и параметры ордера\n";
        echo "  php bin/cli.php strategy:s1:run             — поставить conditional (адаптер по current mode)\n";
        echo "  php bin/cli.php bybit:wallet                — показать walletBalance\n";
        echo "  php bin/cli.php bybit:positions             — открытые позиции (адаптер по current mode)\n";
        echo "  php bin/cli.php deposit:refresh             — обновить deposit_anchor сейчас\n";
        echo "  php bin/cli.php paper:reset                 — очистить paper_orders/paper_positions\n";
        echo "  php bin/cli.php trades:show [N]             — последние N trades (по умолч. 20)\n";
        echo "  php bin/cli.php orders:show [trade_id]      — ордера для trade\n";
        exit($cmd === '' ? 0 : 1);
}

// ────────────────────────────────────────────────────────────

function cmdMigrate(string $root): void
{
    $migrator = new Migrator($root . '/data/migrations');
    $applied  = $migrator->migrate();

    if (count($applied) === 0) {
        echo "Миграции: всё уже применено.\n";
    } else {
        echo "Применены миграции:\n";
        foreach ($applied as $v) echo "  - {$v}\n";
    }

    // Засев дефолтов
    $defaults   = (array)Config::bootstrap('defaults', []);
    $strategies = require $root . '/config/strategies.php';
    $migrator->seedDefaults($defaults, $strategies);
    echo "Дефолты настроек и стратегии засеяны.\n";
}

function cmdAuthInit(): void
{
    echo "Создание пользователя UI\n";
    echo "Логин: ";
    $username = trim((string)fgets(STDIN));
    if ($username === '') {
        fwrite(STDERR, "Логин не может быть пустым.\n");
        exit(1);
    }

    echo "Пароль: ";
    if (function_exists('shell_exec') && PHP_OS_FAMILY !== 'Windows') {
        shell_exec('stty -echo');
        $password = trim((string)fgets(STDIN));
        shell_exec('stty echo');
        echo "\n";
    } else {
        $password = trim((string)fgets(STDIN));
    }
    if (strlen($password) < 8) {
        fwrite(STDERR, "Пароль слишком короткий (нужно ≥ 8 символов).\n");
        exit(1);
    }

    try {
        $info = Auth::createUser($username, $password);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Ошибка: " . $e->getMessage() . "\n");
        exit(1);
    }

    echo "\nПользователь создан: {$info['username']}\n\n";
    echo "TOTP-секрет (введите вручную, если не используете QR):\n  {$info['totp_secret']}\n\n";
    echo "Provisioning URL (для Google Authenticator / 2FAS / Authy):\n  {$info['totp_provisioning']}\n\n";
    echo "В Google Authenticator: пункт «Ввести ключ настройки» → имя {$info['username']}, ключ — выше.\n";
    echo "Сохраните секрет в надёжном месте — он показан один раз.\n";
}

function cmdStrategiesList(string $root): void
{
    $config = require $root . '/config/strategies.php';
    new StrategyRegistry($config);

    $rows = Database::pdo()->query('SELECT id, name, enabled, is_automatic FROM strategies ORDER BY id')->fetchAll();
    echo "Стратегии:\n";
    printf("  %-4s %-50s %-10s %-12s\n", 'ID', 'Имя', 'Enabled', 'Auto');
    foreach ($rows as $r) {
        printf(
            "  %-4s %-50s %-10s %-12s\n",
            $r['id'],
            $r['name'],
            $r['enabled'] ? 'yes' : 'no',
            $r['is_automatic'] ? 'yes' : 'no'
        );
    }
}

function cmdSettingsShow(): void
{
    $rows = Database::pdo()->query('SELECT key, value, updated_at FROM settings ORDER BY key')->fetchAll();
    echo "Настройки (settings):\n";
    foreach ($rows as $r) {
        printf("  %-45s = %s\n", $r['key'], $r['value']);
    }

    $rows = Database::pdo()->query(
        'SELECT strategy_id, key, value FROM strategy_settings ORDER BY strategy_id, key'
    )->fetchAll();
    if ($rows) {
        echo "\nПереопределения по стратегиям (strategy_settings):\n";
        foreach ($rows as $r) {
            printf("  [%s] %-40s = %s\n", $r['strategy_id'], $r['key'], $r['value']);
        }
    }
}

// ──────────────────────────────────────────────────
// Bybit
// ──────────────────────────────────────────────────

function cmdBybitServerTime(): void
{
    $client = BybitClient::default();
    echo "Network (signed): {$client->getNetwork()}\n";
    echo "Base URL public  (kline/tickers): {$client->getBaseUrlPublic()}\n";
    echo "Base URL private (orders/positions): {$client->getBaseUrlPrivate()}\n";
    echo "Pinging /v5/market/time (public → mainnet) ...\n";

    $r = ServerTime::ping($client);
    if ($r['ok']) {
        echo "OK\n";
        echo "  server time (ms): {$r['time_ms']}\n";
        echo "  drift vs local (ms): {$r['drift_ms']}\n";
        if (abs((int)$r['drift_ms']) > 1000) {
            echo "  ⚠ Дрейф больше 1 секунды — синхронизируйте время на сервере (timedatectl).\n";
        }
    } else {
        echo "FAIL\n";
        echo "  category: {$r['category']}\n";
        echo "  error: " . ($r['error'] ?? '-') . "\n";
        exit(1);
    }
}

function cmdBybitAuthCheck(): void
{
    $client = BybitClient::default();
    echo "Network: {$client->getNetwork()}\n";
    if (!$client->isAuthorized()) {
        echo "FAIL: API ключи не настроены в .env\n";
        echo "Установите BYBIT_API_KEY_TESTNET / BYBIT_API_SECRET_TESTNET для testnet,\n";
        echo "или BYBIT_API_KEY_MAINNET / BYBIT_API_SECRET_MAINNET для live.\n";
        exit(1);
    }
    echo "Calling /v5/account/wallet-balance ...\n";

    $r = ServerTime::authCheck($client);
    if ($r['ok']) {
        echo "OK — подпись принята.\n";
    } else {
        echo "FAIL\n";
        echo "  category: {$r['category']}\n";
        echo "  retCode: " . ($r['ret_code'] ?? '-') . "\n";
        echo "  retMsg: " . ($r['ret_msg'] ?? '-') . "\n";
        echo "  error: " . ($r['error'] ?? '-') . "\n";
        exit(1);
    }
}

function cmdBybitRefreshInstruments(): void
{
    $client = BybitClient::default();
    echo "Refreshing instruments cache ({$client->getNetwork()}) ...\n";
    try {
        $count = MarketInfo::refresh($client);
        echo "OK — обновлено инструментов: {$count}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// ──────────────────────────────────────────────────
// Signals
// ──────────────────────────────────────────────────

function cmdSignalsImport(): void
{
    echo "Импорт сигналов из " . Config::bootstrap('paths.signals_source') . " ...\n";
    try {
        $r = Importer::run();
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
        exit(1);
    }
    printf("  total in file:    %d\n", $r['total_in_file']);
    printf("  imported:         %d\n", $r['imported']);
    printf("  skipped (older):  %d\n", $r['skipped']);
    printf("  failed:           %d\n", $r['failed']);
    printf("  resolved:         %d\n", $r['resolved']);
    printf("  unresolved:       %d\n", $r['unresolved']);
    printf("  source mtime:     %s (age %ds)\n", $r['source_mtime'], (int)$r['source_mtime_age_sec']);
    printf("  freshness ok:     %s\n", $r['freshness_ok'] ? 'yes' : 'NO');
}

function cmdSignalsResolve(): void
{
    echo "Перерезолв unresolved сигналов ...\n";
    $r = MarketInfo::reresolveSignals();
    echo "  resolved:         {$r['resolved']}\n";
    echo "  still unresolved: {$r['still_unresolved']}\n";
}

function cmdSignalsShow(int $limit): void
{
    $limit = max(1, min(500, $limit));
    $rows = Database::pdo()->query(
        "SELECT date, time, symbol, side, target, signal_type, potential, bybit_symbol, resolution_status
         FROM signals ORDER BY saved_at_utc DESC LIMIT {$limit}"
    )->fetchAll();
    if (count($rows) === 0) {
        echo "Сигналов нет.\n";
        return;
    }
    printf("%-10s %-8s %-8s %-5s %-10s %-3s %-3s %-15s %-12s\n",
        'date', 'time', 'symbol', 'side', 'target', 'st', 'p?', 'bybit_symbol', 'res');
    foreach ($rows as $r) {
        printf("%-10s %-8s %-8s %-5s %-10s %-3s %-3s %-15s %-12s\n",
            $r['date'], $r['time'], $r['symbol'], $r['side'], $r['target'],
            $r['signal_type'], $r['potential'] ? 'y' : 'n',
            $r['bybit_symbol'] ?? '-', $r['resolution_status']);
    }
}

function cmdSignalsUnresolved(): void
{
    $rows = Database::pdo()->query(
        "SELECT symbol, COUNT(*) as cnt, MAX(saved_at_utc) as last_seen
         FROM signals WHERE resolution_status = 'unresolved'
         GROUP BY symbol ORDER BY cnt DESC"
    )->fetchAll();

    if (count($rows) === 0) {
        echo "Все сигналы сопоставлены с Bybit-инструментами.\n";
        return;
    }

    echo "Несопоставленные тикеры:\n";
    printf("  %-12s %-6s %s\n", 'symbol', 'count', 'last_seen');
    foreach ($rows as $r) {
        printf("  %-12s %-6d %s\n", $r['symbol'], (int)$r['cnt'], $r['last_seen']);
    }
    echo "\nДля сопоставления выполните:\n";
    echo "  php bin/cli.php symbols:alias <SOURCE> <BYBIT_SYMBOL>\n";
    echo "Например: php bin/cli.php symbols:alias PEPE 1000PEPEUSDT\n";
    echo "После добавления алиасов: php bin/cli.php signals:resolve\n";
}

function cmdSymbolsAlias(string $source, string $bybit, ?string $note): void
{
    if ($source === '' || $bybit === '') {
        fwrite(STDERR, "Использование: php bin/cli.php symbols:alias <SOURCE> <BYBIT_SYMBOL> [note]\n");
        exit(1);
    }
    try {
        MarketInfo::addAlias(strtoupper($source), strtoupper($bybit), $note, 'cli');
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
        exit(1);
    }
    echo "OK — алиас добавлен: {$source} → {$bybit}\n";
    echo "Запустите 'php bin/cli.php signals:resolve' для обновления старых сигналов.\n";
}

// ──────────────────────────────────────────────────
// Strategy 1 / Paper (Stage 2 item 3)
// ──────────────────────────────────────────────────

function cmdStrategyS1Scan(string $root, bool $dryRun): void
{
    // v0.8.0.5: выбираем адаптер по текущему mode (paper / testnet / live),
    // а не хардкодим paper — иначе в live расчёты s1 идут по симуляции.
    // v0.9.0-step4d: для testnet/live multi-account — используем pickAdapterCli (--account=N).
    $mode    = (string)Config::get('mode', null, 'paper');
    $accId   = cliParseAccountArg();
    $adapter = pickAdapterCli($mode, $accId);

    // Deposit anchor
    $stmt = Database::pdo()->prepare(
        "SELECT value FROM deposit_snapshots WHERE mode = :m ORDER BY ts DESC LIMIT 1"
    );
    $stmt->execute([':m' => $mode]);
    $depositAnchor = (float)($stmt->fetchColumn() ?: Config::get('paper_initial_deposit_usdt', null, 300.0));

    $positions  = $adapter->getPositions();
    $openOrders = $adapter->getOpenOrders();

    $context = [
        'adapter'        => $adapter,
        'deposit_anchor' => $depositAnchor,
        'current_balance'=> $depositAnchor,
        'positions'      => $positions,
        'open_orders'    => $openOrders,
        'mode'           => $mode,
    ];

    $strategiesConfig = require $root . '/config/strategies.php';
    $registry         = new StrategyRegistry($strategiesConfig);

    try {
        $s1 = $registry->get('s1');
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
        exit(1);
    }

    echo "Strategy 1 — сканирование сигналов" . ($dryRun ? " (dry-run)" : "") . "\n";
    echo "Режим: {$mode}, deposit_anchor: {$depositAnchor} USDT\n\n";

    try {
        $intents = $s1->collectAutoIntents($context);
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL collectAutoIntents: " . $e->getMessage() . "\n");
        exit(1);
    }

    if (empty($intents)) {
        echo "Нет подходящих сигналов для текущего часа.\n";
        return;
    }

    foreach ($intents as $i => $intent) {
        echo "─── Intent #" . ($i + 1) . " ───────────────────────────────\n";
        echo sprintf("  Символ:       %s\n", $intent['symbol'] ?? '-');
        echo sprintf("  Сторона:      %s\n", $intent['side'] ?? '-');
        echo sprintf("  entry_ref:    %.8f\n", (float)($intent['entry_ref'] ?? 0));
        echo sprintf("  TP:           %.8f\n", (float)($intent['tp_price'] ?? 0));
        echo sprintf("  SL:           %.8f\n", (float)($intent['sl_price'] ?? 0));
        echo sprintf("  Qty (монет):  %.4f\n", (float)($intent['qty'] ?? 0));
        echo sprintf("  Плечо:        %d×\n", (int)($intent['leverage'] ?? 0));
        echo sprintf("  p_TP_used:    %.4f%%\n", (float)($intent['p_tp_used'] ?? 0));
        echo sprintf("  unleveraged:  %.4f USDT\n", (float)($intent['unleveraged_usdt'] ?? 0));
        echo sprintf("  qty_usdt:     %.4f USDT\n", (float)($intent['order_qty_usdt'] ?? 0));
        echo sprintf("  signal_id:    %d\n", (int)($intent['signal_id'] ?? 0));
        echo sprintf("  order_link:   %s\n", $intent['order_link_id'] ?? '-');
        echo "\n";

        if (!$dryRun) {
            echo "  → Запустите 'php bin/cli.php strategy:s1:run' для реальной постановки.\n";
        }
    }
}

function cmdStrategyS1Run(string $root): void
{
    // v0.8.0.5: выбираем адаптер по текущему mode (paper / testnet / live).
    // При mode=live ордер пойдёт на mainnet Bybit, а не в paper-симулятор.
    // v0.9.0-step4d: для testnet/live multi-account — pickAdapterCli (--account=N).
    $mode    = (string)Config::get('mode', null, 'paper');
    $accId   = cliParseAccountArg();
    $adapter = pickAdapterCli($mode, $accId);

    $stmt = Database::pdo()->prepare(
        "SELECT value FROM deposit_snapshots WHERE mode = :m ORDER BY ts DESC LIMIT 1"
    );
    $stmt->execute([':m' => $mode]);
    $depositAnchor = (float)($stmt->fetchColumn() ?: Config::get('paper_initial_deposit_usdt', null, 300.0));

    $positions  = $adapter->getPositions();
    $openOrders = $adapter->getOpenOrders();

    $context = [
        'adapter'        => $adapter,
        'deposit_anchor' => $depositAnchor,
        'current_balance'=> $depositAnchor,
        'positions'      => $positions,
        'open_orders'    => $openOrders,
        'mode'           => $mode,
    ];

    $strategiesConfig = require $root . '/config/strategies.php';
    $registry         = new StrategyRegistry($strategiesConfig);

    try {
        $s1 = $registry->get('s1');
        $intents = $s1->collectAutoIntents($context);
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
        exit(1);
    }

    if (empty($intents)) {
        echo "Нет подходящих сигналов.\n";
        return;
    }

    $pdo = Database::pdo();
    $now = gmdate('Y-m-d\\TH:i:s.v\\Z');

    foreach ($intents as $intent) {
        $symbol = (string)($intent['symbol'] ?? '');

        // Создать trade
        $stmt = $pdo->prepare(
            'INSERT INTO trades
             (mode, strategy_id, symbol, side, signal_target_pct, signal_w7, signal_rsi,
              signal_id, status, entry_ref, leverage, margin_mode, tp_init, sl_init,
              sl_current, p_for_strategy_calc, created_at)
             VALUES
             (:mode,:sid,:sym,:side,:tp,:w7,:rsi,:sigid,:status,:entry,:lev,:mm,:tpinit,:slinit,:slcur,:p,:now)'
        );
        $stmt->execute([
            ':mode'   => $mode,
            ':sid'    => 's1',
            ':sym'    => $symbol,
            ':side'   => (string)($intent['trade_side'] ?? $intent['side'] ?? 'long'),
            ':tp'     => (float)($intent['signal_target_pct'] ?? 0),
            ':w7'     => isset($intent['signal_w7'])  ? (int)$intent['signal_w7']    : null,
            ':rsi'    => isset($intent['signal_rsi']) ? (float)$intent['signal_rsi'] : null,
            ':sigid'  => isset($intent['signal_id'])  ? (int)$intent['signal_id']    : null,
            ':status' => 'PENDING_CONDITIONAL',
            ':entry'  => (float)($intent['entry_ref']  ?? 0),
            ':lev'    => (int)($intent['leverage']     ?? 1),
            ':mm'     => (string)($intent['margin_mode'] ?? 'cross'),
            ':tpinit' => (float)($intent['tp_price']   ?? 0),
            ':slinit' => (float)($intent['sl_price']   ?? 0),
            ':slcur'  => (float)($intent['sl_price']   ?? 0),
            ':p'      => (float)($intent['p_for_strategy_calc'] ?? 0),
            ':now'    => $now,
        ]);
        $tradeId = (int)$pdo->lastInsertId();

        $linkId = "s1-{$tradeId}-entry-" . bin2hex(random_bytes(4));
        $intent['trade_id']      = $tradeId;
        $intent['order_link_id'] = $linkId;

        echo "Ставим conditional для {$symbol} (trade_id={$tradeId})...\n";

        try {
            $orderId = $adapter->placeConditional($intent);
            $pdo->prepare(
                'UPDATE trades SET order_link_id_open = :link, order_id_open = :oid WHERE id = :id'
            )->execute([':link' => $linkId, ':oid' => $orderId, ':id' => $tradeId]);

            echo "OK — order_id={$orderId}, link={$linkId}\n";
            echo sprintf("  trigger: %.8f, qty: %.4f, TP: %.8f, SL: %.8f\n",
                (float)$intent['trigger_price'],
                (float)$intent['qty'],
                (float)$intent['tp_price'],
                (float)$intent['sl_price']
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, "FAIL place: " . $e->getMessage() . "\n");
            $pdo->prepare("UPDATE trades SET status = 'CANCELLED' WHERE id = :id")->execute([':id' => $tradeId]);
        }
    }
}

function cmdBybitWallet(): void
{
    $client = BybitClient::default();
    if (!$client->isAuthorized()) {
        // Paper mode: показываем из БД
        $stmt = Database::pdo()->prepare(
            "SELECT value, ts FROM deposit_snapshots ORDER BY ts DESC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row) {
            echo "Баланс (из deposit_snapshots): {$row['value']} USDT\n";
            echo "Обновлено: {$row['ts']}\n";
        } else {
            $def = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
            echo "Баланс (дефолт paper): {$def} USDT\n";
        }
        return;
    }

    $resp = $client->getWalletBalance('UNIFIED');
    if ($resp['category'] !== Errors::SUCCESS) {
        fwrite(STDERR, "FAIL: " . ($resp['ret_msg'] ?? $resp['error'] ?? 'unknown') . "\n");
        exit(1);
    }

    $accts = $resp['result']['list'] ?? [];
    if (empty($accts)) {
        echo "Нет данных о балансе.\n";
        return;
    }

    $acct = $accts[0];
    echo "Wallet Balance (UTA UNIFIED):\n";
    echo "  totalWalletBalance:    " . ($acct['totalWalletBalance']    ?? '-') . " USDT\n";
    echo "  totalEquity:           " . ($acct['totalEquity']           ?? '-') . " USDT\n";
    echo "  totalAvailableBalance: " . ($acct['totalAvailableBalance'] ?? '-') . " USDT\n";
    echo "  totalMarginBalance:    " . ($acct['totalMarginBalance']    ?? '-') . " USDT\n";
}

function cmdBybitPositions(): void
{
    // v0.8.0.5: выбираем адаптер по текущему mode.
    // v0.9.0-step4d: для testnet/live multi-account — pickAdapterCli (--account=N).
    $mode      = (string)Config::get('mode', null, 'paper');
    $exchange  = $mode === 'pause' ? 'paper' : $mode;
    $accId     = cliParseAccountArg();
    $adapter   = pickAdapterCli($mode, $accId);
    $accLabel  = ($accId !== null) ? " account=#{$accId}" : '';
    $positions = $adapter->getPositions();

    if (empty($positions)) {
        echo "Нет открытых позиций ({$exchange}{$accLabel}).\n";
        return;
    }

    echo "Открытые позиции ({$exchange}{$accLabel}):\n";
    printf("  %-15s %-5s %-10s %-10s %-12s %-12s %-12s %-8s\n",
        'symbol', 'side', 'qty', 'entry', 'sl', 'tp', 'trailing', 'opened');
    foreach ($positions as $pos) {
        printf("  %-15s %-5s %-10.4f %-10.6f %-12s %-12s %-8s %-20s\n",
            $pos['symbol'] ?? '-',
            $pos['side']   ?? '-',
            (float)($pos['qty'] ?? 0),
            (float)($pos['avg_entry_price'] ?? 0),
            $pos['sl_price']    ?? '-',
            $pos['tp_price']    ?? '-',
            $pos['trailing_pct'] ?? '-',
            substr((string)($pos['opened_at'] ?? ''), 0, 19)
        );
    }
}

function cmdDepositRefresh(): void
{
    $mode   = (string)Config::get('mode', null, 'paper');
    $now    = gmdate('Y-m-d\\TH:i:s.v\\Z');
    $balance = null;

    if ($mode !== 'paper') {
        $client = BybitClient::default();
        if ($client->isAuthorized()) {
            $resp = $client->getWalletBalance('UNIFIED');
            if ($resp['category'] === Errors::SUCCESS && isset($resp['result']['list'][0])) {
                $balance = (float)$resp['result']['list'][0]['totalWalletBalance'];
            }
        }
    }

    if ($balance === null) {
        $balance = (float)Config::get('paper_initial_deposit_usdt', null, 300.0);
        echo "Используем paper_initial_deposit_usdt = {$balance} USDT\n";
    }

    Database::pdo()->prepare(
        'INSERT INTO deposit_snapshots (mode, ts, value, source) VALUES (:m, :t, :v, :s)'
    )->execute([':m' => $mode, ':t' => $now, ':v' => $balance, ':s' => 'manual']);

    echo "deposit_anchor обновлён: {$balance} USDT (mode={$mode})\n";
}

function cmdPaperReset(): void
{
    echo "Очищаем paper_orders, paper_positions, orders (paper=1)...\n";

    $pdo = Database::pdo();
    $pdo->exec("DELETE FROM paper_positions");
    $pdo->exec("DELETE FROM paper_orders");
    $pdo->exec("DELETE FROM orders WHERE paper = 1");
    $pdo->exec("DELETE FROM positions WHERE paper = 1");
    $pdo->exec("UPDATE trades SET status = 'CANCELLED' WHERE status = 'PENDING_CONDITIONAL' AND mode = 'paper'");

    echo "Готово.\n";
}

function cmdTradesShow(int $limit): void
{
    $limit = max(1, min(500, $limit));
    $rows  = Database::pdo()->query(
        "SELECT id, mode, strategy_id, symbol, side, status, signal_target_pct, leverage,
                entry_ref, entry_real, realized_pnl_usdt, created_at, opened_at, closed_at
         FROM trades ORDER BY created_at DESC LIMIT {$limit}"
    )->fetchAll();

    if (empty($rows)) {
        echo "Сделок нет.\n";
        return;
    }

    printf("%-5s %-6s %-3s %-15s %-5s %-22s %-6s %-10s %-10s %-8s %-19s\n",
        'id', 'mode', 'sid', 'symbol', 'side', 'status', 'p%', 'entry_ref', 'pnl', 'lev', 'created');
    foreach ($rows as $r) {
        printf("%-5d %-6s %-3s %-15s %-5s %-22s %-6s %-10s %-10s %-8d %-19s\n",
            (int)$r['id'],
            $r['mode'],
            $r['strategy_id'],
            $r['symbol'],
            $r['side'],
            $r['status'],
            $r['signal_target_pct'] !== null ? number_format((float)$r['signal_target_pct'], 2) : '-',
            $r['entry_ref'] !== null ? number_format((float)$r['entry_ref'], 6) : '-',
            $r['realized_pnl_usdt'] !== null ? number_format((float)$r['realized_pnl_usdt'], 4) : '-',
            (int)($r['leverage'] ?? 0),
            substr((string)$r['created_at'], 0, 19)
        );
    }
}

function cmdOrdersShow(?int $tradeId): void
{
    if ($tradeId !== null) {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM orders WHERE trade_id = :tid ORDER BY placed_at DESC'
        );
        $stmt->execute([':tid' => $tradeId]);
    } else {
        $stmt = Database::pdo()->query(
            'SELECT * FROM orders ORDER BY placed_at DESC LIMIT 50'
        );
    }
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        echo "Ордеров нет" . ($tradeId !== null ? " для trade #{$tradeId}" : "") . ".\n";
        return;
    }

    printf("%-5s %-7s %-20s %-5s %-10s %-10s %-10s %-10s %-10s %-19s\n",
        'id', 'trade', 'purpose', 'side', 'qty', 'trigger', 'status', 'exchange', 'link', 'placed');
    foreach ($rows as $r) {
        printf("%-5d %-7d %-20s %-5s %-10.4f %-10s %-10s %-10s %-10s %-19s\n",
            (int)$r['id'],
            (int)$r['trade_id'],
            $r['purpose'],
            $r['side'],
            (float)$r['qty'],
            $r['trigger_price'] !== null ? number_format((float)$r['trigger_price'], 6) : '-',
            $r['status'],
            $r['exchange'] ?? ($r['paper'] ? 'paper' : 'live'),
            substr((string)($r['bybit_order_link_id'] ?? ''), 0, 10),
            substr((string)($r['placed_at'] ?? ''), 0, 19)
        );
    }
}

// ────────────────────────────────────────────────
// Stage 3: режим, paper, reconcile, exchange:status
// ────────────────────────────────────────────────

function cmdBybitModeGet(): void
{
    $mode = (string)Config::get('mode', null, 'paper');
    echo "Текущий режим: {$mode}\n";
}

function cmdBybitModeSet(string $newMode, bool $confirm): void
{
    $allowed = ['paper', 'testnet', 'live', 'pause'];
    if (!in_array($newMode, $allowed, true)) {
        fwrite(STDERR, "Недопустимый режим: {$newMode}. Допустимые: " . implode(', ', $allowed) . "\n");
        exit(1);
    }

    if ($newMode === 'live' && !$confirm) {
        fwrite(STDERR, "ВНИМАНИЕ: режим 'live' использует РЕАЛЬНЫЕ ДЕНЬГИ!\n");
        fwrite(STDERR, "Добавьте флаг --confirm для подтверждения:\n");
        fwrite(STDERR, "  php bin/cli.php bybit:mode set live --confirm\n");
        exit(1);
    }

    $oldMode = (string)Config::get('mode', null, 'paper');
    Config::set('mode', $newMode);

    EventRecorder::event(EventRecorder::INFO, 'mode_changed', null, [
        'from' => $oldMode,
        'to'   => $newMode,
    ]);

    echo "Режим изменён: {$oldMode} → {$newMode}\n";

    if ($newMode === 'live') {
        echo "\n!! Работаете с реальными деньгами. Будьте осторожны! !!\n";
    }
    if ($newMode === 'pause') {
        echo "Режим pause: новые сигналы не будут обрабатываться, существующие позиции доживут.\n";
    }
}

function cmdPaperCloseAll(): void
{
    $pdo = Database::pdo();
    $now = gmdate('Y-m-d\\TH:i:s.v\\Z');

    echo "Закрываем все paper-позиции...\n";

    // Отменить подвешенные paper-ордера
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'cancelled', cancelled_at = :now
         WHERE exchange = 'paper' AND status IN ('placed','pending')"
    );
    $stmt->execute([':now' => $now]);
    $cancelledOrders = $stmt->rowCount();

    // Отменить в paper_orders
    $pdo->exec("UPDATE paper_orders SET status = 'cancelled' WHERE status = 'pending'");

    // Закрыть paper-позиции
    $stmt2 = $pdo->prepare(
        "UPDATE positions SET closed_at = :now, close_reason = 'manual_close_all'
         WHERE exchange = 'paper' AND closed_at IS NULL"
    );
    $stmt2->execute([':now' => $now]);
    $closedPositions = $stmt2->rowCount();

    // Закрыть в paper_positions
    $pdo->prepare(
        "UPDATE paper_positions SET closed_at = :now, close_reason = 'manual_close_all' WHERE closed_at IS NULL"
    )->execute([':now' => $now]);

    // Обновить trades
    $pdo->prepare(
        "UPDATE trades SET status = 'CANCELLED', closed_at = :now
         WHERE status = 'PENDING_CONDITIONAL' AND mode = 'paper'"
    )->execute([':now' => $now]);

    $pdo->prepare(
        "UPDATE trades SET status = 'CLOSED_LOSS', closed_at = :now
         WHERE status = 'OPEN' AND mode = 'paper'"
    )->execute([':now' => $now]);

    EventRecorder::event(EventRecorder::INFO, 'paper_close_all', null, [
        'cancelled_orders'  => $cancelledOrders,
        'closed_positions'  => $closedPositions,
    ]);

    echo "Отменено ордеров: {$cancelledOrders}\n";
    echo "Закрыто позиций: {$closedPositions}\n";
}

function cmdReconcileNow(?string $exchange): void
{
    $exchanges = [];
    if ($exchange !== null) {
        if (!in_array($exchange, ['testnet', 'live'], true)) {
            fwrite(STDERR, "Ошибка: exchange должен быть 'testnet' или 'live'\n");
            exit(1);
        }
        $exchanges = [$exchange];
    } else {
        $exchanges = ['testnet', 'live'];
    }

    foreach ($exchanges as $exch) {
        echo "Сверка для {$exch}...\n";
        try {
            $adapter = new BybitAdapter($exch);

            // v0.8.0.6: полный tick() — внутри работает LiveReconciler:
            //   A) PENDING_CONDITIONAL→OPEN (через getExecutions),
            //   B) отмена фантомных ордеров на бирже без локальной записи,
            //   C) reconcilePositions — обновление last_price и CLOSED_PROFIT/LOSS,
            //   D) reconcilePendingMarketPrices — last_seen_price для UI-баров.
            try {
                $adapter->tick();
                echo "  tick() OK — LiveReconciler отработал\n";
            } catch (\Throwable $e) {
                echo "  tick() FAIL: " . $e->getMessage() . "\n";
            }

            $remoteOrders    = $adapter->getOpenOrders();
            $remotePositions = $adapter->getPositions();

            $pdo = Database::pdo();
            $now = gmdate('Y-m-d\\TH:i:s.v\\Z');

            $remoteByLinkId = [];
            foreach ($remoteOrders as $ro) {
                $lid = (string)($ro['orderLinkId'] ?? '');
                if ($lid !== '') {
                    $remoteByLinkId[$lid] = true;
                }
            }

            $localStmt = $pdo->prepare(
                "SELECT o.id, o.bybit_order_link_id, o.trade_id
                 FROM orders o WHERE o.exchange = :exch AND o.status = 'placed'"
            );
            $localStmt->execute([':exch' => $exch]);
            $localOrders = $localStmt->fetchAll();

            $orphanCount = 0;
            foreach ($localOrders as $lo) {
                $lid = (string)($lo['bybit_order_link_id'] ?? '');
                if ($lid !== '' && !isset($remoteByLinkId[$lid])) {
                    $pdo->prepare(
                        "UPDATE orders SET status = 'cancelled', cancelled_at = :now WHERE id = :id"
                    )->execute([':now' => $now, ':id' => (int)$lo['id']]);
                    $orphanCount++;
                    echo "  [WARN] Ордер {$lid} не найден на бирже — помечен cancelled\n";
                }
            }

            echo "  Remote orders:    " . count($remoteOrders) . "\n";
            echo "  Remote positions: " . count($remotePositions) . "\n";
            echo "  Local orphans:    {$orphanCount}\n";

            EventRecorder::event(EventRecorder::INFO, 'reconcile_manual', null, [
                'exchange'        => $exch,
                'remote_orders'   => count($remoteOrders),
                'remote_positions'=> count($remotePositions),
                'orphans'         => $orphanCount,
            ]);

        } catch (\Throwable $e) {
            fwrite(STDERR, "  FAIL: " . $e->getMessage() . "\n");
        }
    }
}

function cmdExchangeStatus(): void
{
    $pdo = Database::pdo();

    echo "Статус по биржам:\n";
    printf("  %-12s %-15s %-18s %-15s\n",
        'exchange', 'open_orders', 'open_positions', 'total_pnl');

    $exchanges = ['paper', 'testnet', 'live'];
    foreach ($exchanges as $exch) {
        $stmt1 = $pdo->prepare(
            "SELECT COUNT(*) FROM orders WHERE exchange = :e AND status IN ('placed','pending')"
        );
        $stmt1->execute([':e' => $exch]);
        $orderCount = (int)$stmt1->fetchColumn();

        $stmt2 = $pdo->prepare(
            "SELECT COUNT(*) FROM positions WHERE exchange = :e AND closed_at IS NULL"
        );
        $stmt2->execute([':e' => $exch]);
        $posCount = (int)$stmt2->fetchColumn();

        $stmt3 = $pdo->prepare(
            "SELECT COALESCE(SUM(realised_pnl_usdt), 0) FROM positions WHERE exchange = :e AND realised_pnl_usdt IS NOT NULL"
        );
        $stmt3->execute([':e' => $exch]);
        $totalPnl = (float)$stmt3->fetchColumn();

        printf("  %-12s %-15d %-18d %-15s\n",
            $exch, $orderCount, $posCount,
            ($totalPnl >= 0 ? '+' : '') . number_format($totalPnl, 4) . ' USDT'
        );
    }
}

// ──────────────────────────────────────────────────
// v0.9.0-step4d: helpers для multi-account CLI
// ──────────────────────────────────────────────────

/**
 * Парсит --account=N из глобального $argv. Возвращает int или null, если флаг не задан.
 * Допускает форму --account 5 (через пробел).
 *
 * @return int|null
 */
function cliParseAccountArg(): ?int
{
    global $argv;
    if (!is_array($argv)) return null;
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $a = $argv[$i];
        if (!is_string($a)) continue;
        if (strpos($a, '--account=') === 0) {
            $v = substr($a, 10);
            if ($v === '' || !ctype_digit($v)) {
                fwrite(STDERR, "FAIL: --account=N требует положительное целое (получено: '{$v}')\n");
                exit(2);
            }
            return (int)$v;
        }
        if ($a === '--account' && isset($argv[$i + 1])) {
            $v = (string)$argv[$i + 1];
            if (!ctype_digit($v)) {
                fwrite(STDERR, "FAIL: --account N требует положительное целое (получено: '{$v}')\n");
                exit(2);
            }
            return (int)$v;
        }
    }
    return null;
}

/**
 * Выбирает адаптер для CLI с учётом multi-account:
 * - paper / pause → PaperAdapter (account_id игнорируется);
 * - testnet / live:
 *     - если $accountId задан → forAccount($accountId), сверяем network;
 *     - иначе берём enabled-аккаунты этой сети:
 *         - 0 → ошибка;
 *         - 1 → авто;
 *         - >1 → ошибка, требуется --account=N.
 *
 * @return \BybitBot\Exchange\ExchangeAdapter
 */
function pickAdapterCli(string $mode, ?int $accountId)
{
    if ($mode === 'paper' || $mode === 'pause') {
        if ($accountId !== null) {
            fwrite(STDERR, "WARN: --account=N игнорируется для mode={$mode}\n");
        }
        return AdapterFactory::forCurrentMode();
    }

    if ($mode !== 'testnet' && $mode !== 'live') {
        fwrite(STDERR, "FAIL: неизвестный mode '{$mode}'\n");
        exit(2);
    }

    if ($accountId !== null) {
        try {
            $adapter = AdapterFactory::forAccount($accountId);
        } catch (\Throwable $e) {
            fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
            exit(2);
        }
        $acc = BybitAccountsRepo::find($accountId);
        if ($acc !== null && (string)$acc['network'] !== $mode) {
            fwrite(STDERR, "FAIL: аккаунт #{$accountId} имеет network={$acc['network']}, а текущий mode={$mode}\n");
            exit(2);
        }
        return $adapter;
    }

    $accs = BybitAccountsRepo::getEnabledForNetwork($mode);
    if (empty($accs)) {
        fwrite(STDERR, "FAIL: нет enabled-аккаунтов для network={$mode}. Добавьте/включите аккаунт в /accounts.\n");
        exit(2);
    }
    if (count($accs) > 1) {
        fwrite(STDERR, "FAIL: enabled-аккаунтов для network={$mode}: " . count($accs)
            . ". Укажите --account=N. Доступные id: "
            . implode(', ', array_map(static function ($a) { return '#' . (int)$a['id'] . ' (' . $a['name'] . ')'; }, $accs))
            . "\n");
        exit(2);
    }
    $only = $accs[0];
    return AdapterFactory::forAccount((int)$only['id']);
}
