-- Bybit Futures Bot — начальная схема БД (SQLite)
-- См. spec.md §12.1

-- ───────────────────────────────────────────────────────────
-- Сделки
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS trades (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    mode                  TEXT NOT NULL,            -- 'paper'|'testnet'|'live'
    strategy_id           TEXT NOT NULL,            -- 's1'|'s2'|'s3'
    symbol                TEXT NOT NULL,
    side                  TEXT NOT NULL,            -- 'long'|'short'
    signal_target_pct     REAL,                     -- NULL для S2/S3
    signal_w7             INTEGER,
    signal_rsi            REAL,
    signal_payload_json   TEXT,

    -- Ручные стратегии
    manual_tp_price       REAL,
    manual_sl_price       REAL,
    manual_distance_pct   REAL,

    -- p, по которому считаются avg/TS/SL после открытия
    p_for_strategy_calc   REAL,

    status                TEXT NOT NULL,            -- enum (см. §3.3)
    manual_override       INTEGER NOT NULL DEFAULT 0,

    -- Цены и лот
    entry_ref             REAL,
    entry_real            REAL,
    qty_initial           REAL,
    qty_current           REAL,
    qty_avg               REAL,
    leverage              INTEGER,
    margin_mode           TEXT,                     -- 'cross'|'isolated'

    -- Параметры по фазам
    tp_init               REAL,
    sl_init               REAL,
    sl_current            REAL,
    trailing_pct          REAL,
    trailing_trigger      REAL,
    avg_price             REAL,
    break_even_price      REAL,

    -- ID на бирже
    order_link_id_open    TEXT UNIQUE,
    order_link_id_avg     TEXT UNIQUE,
    order_link_id_part_tp TEXT UNIQUE,
    order_id_open         TEXT,
    order_id_avg          TEXT,
    order_id_part_tp      TEXT,

    -- Даты
    created_at            TEXT NOT NULL,
    opened_at             TEXT,
    averaged_at           TEXT,
    closed_at             TEXT,

    -- Итоги
    realized_pnl_usdt     REAL,
    fees_total_usdt       REAL,
    funding_total_usdt    REAL
);

CREATE INDEX IF NOT EXISTS idx_trades_mode_status     ON trades(mode, status);
CREATE INDEX IF NOT EXISTS idx_trades_strategy_status ON trades(strategy_id, status);
CREATE INDEX IF NOT EXISTS idx_trades_symbol          ON trades(symbol);
CREATE INDEX IF NOT EXISTS idx_trades_created_at      ON trades(created_at);

-- ───────────────────────────────────────────────────────────
-- События по сделкам
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS trade_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    trade_id     INTEGER NOT NULL REFERENCES trades(id),
    ts           TEXT NOT NULL,
    level        TEXT NOT NULL,                    -- INFO|WARN|ERROR|CRITICAL
    kind         TEXT NOT NULL,
    payload_json TEXT
);
CREATE INDEX IF NOT EXISTS idx_trade_events_trade ON trade_events(trade_id, ts);

-- ───────────────────────────────────────────────────────────
-- Funding
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS trade_funding_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    trade_id    INTEGER NOT NULL,
    ts          TEXT NOT NULL,
    amount_usdt REAL NOT NULL,
    rate        REAL,
    applied     INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_funding_trade ON trade_funding_log(trade_id, ts);

-- ───────────────────────────────────────────────────────────
-- Глобальные события
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ts           TEXT NOT NULL,
    level        TEXT NOT NULL,
    kind         TEXT NOT NULL,
    symbol       TEXT,
    payload_json TEXT
);
CREATE INDEX IF NOT EXISTS idx_events_ts    ON events(ts);
CREATE INDEX IF NOT EXISTS idx_events_level ON events(level, ts);

-- ───────────────────────────────────────────────────────────
-- Cron — anti-rerun
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cron_runs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    kind        TEXT NOT NULL,                    -- 'hourly'|'minute'|'daily'
    slot        TEXT NOT NULL,
    status      TEXT NOT NULL,                    -- 'started'|'success'|'failed'
    started_at  TEXT NOT NULL,
    finished_at TEXT,
    message     TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_cron_runs_slot ON cron_runs(kind, slot);

-- ───────────────────────────────────────────────────────────
-- Снапшоты депозита
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS deposit_snapshots (
    id     INTEGER PRIMARY KEY AUTOINCREMENT,
    mode   TEXT NOT NULL,
    ts     TEXT NOT NULL,
    value  REAL NOT NULL,
    source TEXT NOT NULL                          -- 'auto_daily'|'manual'|'paper_initial'
);
CREATE INDEX IF NOT EXISTS idx_deposit_mode_ts ON deposit_snapshots(mode, ts);

-- ───────────────────────────────────────────────────────────
-- Очередь команд из UI
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS commands_queue (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    trade_id     INTEGER,
    ts_created   TEXT NOT NULL,
    ts_done      TEXT,
    status       TEXT NOT NULL,                   -- PENDING|DONE|FAILED
    kind         TEXT NOT NULL,
    payload_json TEXT,
    result_json  TEXT
);
CREATE INDEX IF NOT EXISTS idx_commands_status ON commands_queue(status, ts_created);

-- ───────────────────────────────────────────────────────────
-- Лог HTTP-запросов к API
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_calls (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    ts            TEXT NOT NULL,
    endpoint      TEXT NOT NULL,
    method        TEXT NOT NULL,
    request_json  TEXT,
    response_json TEXT,
    http_status   INTEGER,
    duration_ms   INTEGER,
    trade_id      INTEGER
);
CREATE INDEX IF NOT EXISTS idx_api_calls_ts    ON api_calls(ts);
CREATE INDEX IF NOT EXISTS idx_api_calls_trade ON api_calls(trade_id);

-- ───────────────────────────────────────────────────────────
-- Симулятор (paper)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS paper_orders (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    trade_id      INTEGER NOT NULL,
    kind          TEXT NOT NULL,                  -- conditional_open|avg|trailing|sl|part_tp
    side          TEXT NOT NULL,
    trigger_price REAL,
    limit_price   REAL,
    qty           REAL,
    status        TEXT NOT NULL,                  -- pending|filled|cancelled
    filled_at     TEXT,
    filled_price  REAL
);
CREATE INDEX IF NOT EXISTS idx_paper_trade  ON paper_orders(trade_id);
CREATE INDEX IF NOT EXISTS idx_paper_status ON paper_orders(status);

-- ───────────────────────────────────────────────────────────
-- Настройки
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS strategy_settings (
    strategy_id TEXT NOT NULL,
    key         TEXT NOT NULL,
    value       TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    PRIMARY KEY (strategy_id, key)
);

-- ───────────────────────────────────────────────────────────
-- Метаданные стратегий
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS strategies (
    id           TEXT PRIMARY KEY,                -- 's1','s2','s3'
    name         TEXT NOT NULL,
    enabled      INTEGER NOT NULL DEFAULT 1,
    is_automatic INTEGER NOT NULL DEFAULT 0,
    description  TEXT,
    updated_at   TEXT NOT NULL
);

-- ───────────────────────────────────────────────────────────
-- Авторизация
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    totp_secret   TEXT,
    created_at    TEXT NOT NULL,
    last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS auth_sessions (
    id         TEXT PRIMARY KEY,
    user_id    INTEGER NOT NULL,
    ts_created TEXT NOT NULL,
    ts_expires TEXT NOT NULL,
    ip         TEXT,
    user_agent TEXT
);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON auth_sessions(user_id);

-- ───────────────────────────────────────────────────────────
-- Защита от brute-force на логин
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth_attempts (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    ts   TEXT NOT NULL,
    ip   TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_attempts_ip_ts ON auth_attempts(ip, ts);

-- ───────────────────────────────────────────────────────────
-- Версионирование миграций
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS schema_migrations (
    version    TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL
);
