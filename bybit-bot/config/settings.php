<?php
declare(strict_types=1);

/**
 * Bootstrap-настройки приложения.
 *
 * ВНИМАНИЕ: значения здесь — fallback. Если ключ присутствует в таблице `settings`
 * (или в `strategy_settings` для конкретной стратегии), приоритет у БД.
 *
 * См. spec.md §13 — полный список ключей.
 */

$root = dirname(__DIR__);

return [
    'app' => [
        'env'      => $_ENV['APP_ENV']      ?? 'production',
        'debug'    => filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Europe/Moscow',
        'mode'     => $_ENV['APP_MODE']     ?? 'paper',
        'version'  => '0.9.0-step10',
        'root'     => $root,
    ],

    'paths' => [
        'db'             => $_ENV['DB_PATH']             ?? $root . '/data/bot.db',
        'secrets'        => $_ENV['SECRETS_DIR']         ?? $root . '/data/secrets',
        'signals_source' => $_ENV['SIGNALS_SOURCE_PATH'] ?? '/var/www/stascher.ru/finManager/shared/signalsHourly.json',
        'signals_local'  => $_ENV['SIGNALS_LOCAL_DIR']   ?? $root . '/data/signals',
        'logs'           => $_ENV['LOGS_DIR']            ?? $root . '/logs',
        'migrations'     => $root . '/data/migrations',
        'templates'      => $root . '/templates',
    ],

    'bybit' => [
        'base_url_mainnet' => $_ENV['BYBIT_BASE_URL_MAINNET'] ?? 'https://api.bybit.com',
        'base_url_testnet' => $_ENV['BYBIT_BASE_URL_TESTNET'] ?? 'https://api-testnet.bybit.com',
        'recv_window'      => (int)($_ENV['BYBIT_RECV_WINDOW'] ?? 5000),
    ],

    'session' => [
        'ttl_hours'  => (int)($_ENV['SESSION_TTL_HOURS']  ?? 8),
        'idle_hours' => (int)($_ENV['SESSION_IDLE_HOURS'] ?? 1),
    ],

    // Дефолты, заливаемые в `settings` при первой миграции (см. spec.md §13).
    'defaults' => [
        'mode'                                  => 'paper',
        'delta_pct_of_amplitude'                => 0.08,
        'delta_lookback_candles'                => 24,
        'market_coef'                           => 1.35,
        'taker_fee_pct'                         => 0.055,
        'max_open_positions'                    => 15,
        'max_total_orders_with_pending'         => 20,
        'leverage_cap'                          => 50,
        'qty_safety_margin_pct'                 => 10.0,
        'margin_mode'                           => 'cross',
        'min_signal_target_pct'                 => 2.0,
        'signal_upper_cap.enabled'              => false,
        'signal_upper_cap.pct'                  => 10,
        'long_short_balance.enabled'            => false,
        'long_short_balance.max_share_pct'      => 70,
        'long_short_balance.min_total_to_check' => 6,
        'daily_drawdown.enabled'                => false,
        'daily_drawdown.pct'                    => 10,
        'funding_filter.enabled'                => false,
        'funding_filter.extreme_pct'            => 0.1,
        'min_lot_overshoot.pct'                 => 0,
        'weights_mode'                          => 'priority',
        'paper_initial_deposit_usdt'            => 300,
        'deposit_anchor_ttl_hours'              => 24,
        'signals_freshness_minutes'             => 90,
        'bybit_health_ping_enabled'             => false,
        'ui.alert_loss_pct'                     => 3,
        'ui.deposit_color_thresholds'           => '{"green":0.8,"light_green":0.5,"yellow":0.3,"light_red":0.1}',
        'telegram.enabled'                      => false,
    ],
];
