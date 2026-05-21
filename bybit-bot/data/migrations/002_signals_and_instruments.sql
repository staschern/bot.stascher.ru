-- Bybit Futures Bot — Этап 2: импорт сигналов + кеш инструментов Bybit
-- См. spec.md §3.1, §12.

-- ───────────────────────────────────────────────────────────
-- Сигналы из signalsHourly.json (источник: finManager)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS signals (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Поля как в источнике (даты/время — в MSK, как генерирует finManager)
    date                TEXT NOT NULL,                    -- 'YYYY-MM-DD' (MSK)
    time                TEXT NOT NULL,                    -- 'HH:MM:SS' (MSK)
    saved_at            TEXT NOT NULL,                    -- 'YYYY-MM-DD HH:MM:SS' (MSK)
    saved_at_utc        TEXT NOT NULL,                    -- ISO8601 UTC (для сортировки/фильтра)

    symbol              TEXT NOT NULL,                    -- 'TON', 'STX', 'PEPE' (без USDT)
    side                TEXT NOT NULL,                    -- 'long' | 'short'
    target              REAL NOT NULL,                    -- target price
    strategy            INTEGER NOT NULL,                 -- 1 | 2 | 3 (стратегия из источника)
    potential           INTEGER NOT NULL DEFAULT 0,       -- 0 | 1
    rsi                 REAL,
    w7                  INTEGER,
    w14                 INTEGER,
    w30                 INTEGER,
    w_all               INTEGER,

    -- Маппинг на Bybit-инструмент
    -- resolution_status: 'resolved' | 'unresolved' | 'unsupported'
    bybit_symbol        TEXT,                             -- 'TONUSDT', '1000PEPEUSDT', NULL если не сопоставлено
    resolution_status   TEXT NOT NULL DEFAULT 'unresolved',
    resolved_at         TEXT,                             -- ISO8601 UTC

    -- Идемпотентность импорта
    imported_at         TEXT NOT NULL                     -- ISO8601 UTC
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_signals_unique
    ON signals(date, time, symbol, side);
CREATE INDEX IF NOT EXISTS idx_signals_saved_at_utc
    ON signals(saved_at_utc);
CREATE INDEX IF NOT EXISTS idx_signals_strategy
    ON signals(strategy);
CREATE INDEX IF NOT EXISTS idx_signals_resolution
    ON signals(resolution_status);

-- ───────────────────────────────────────────────────────────
-- Кеш Bybit-инструментов (обновляется в cron_daily)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bybit_instruments (
    symbol              TEXT PRIMARY KEY,                 -- 'BTCUSDT', '1000PEPEUSDT', etc.
    base_coin           TEXT NOT NULL,                    -- 'BTC', '1000PEPE'
    quote_coin          TEXT NOT NULL,                    -- 'USDT'
    contract_type       TEXT,                             -- 'LinearPerpetual', 'LinearFutures'
    status              TEXT,                             -- 'Trading', 'Closed', etc.
    tick_size           REAL,
    qty_step            REAL,
    min_order_qty       REAL,
    max_leverage        REAL,
    raw_json            TEXT,                             -- полный объект из API (для отладки)
    updated_at          TEXT NOT NULL                     -- ISO8601 UTC
);
CREATE INDEX IF NOT EXISTS idx_bybit_instruments_base
    ON bybit_instruments(base_coin);
CREATE INDEX IF NOT EXISTS idx_bybit_instruments_status
    ON bybit_instruments(status);

-- ───────────────────────────────────────────────────────────
-- Ручные алиасы тикеров (приоритет над автопоиском)
-- См. spec.md §3.1.4 — пользователь может переопределить маппинг через UI.
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS symbol_aliases (
    source_symbol       TEXT PRIMARY KEY,                 -- 'PEPE' (как в signalsHourly)
    bybit_symbol        TEXT NOT NULL,                    -- '1000PEPEUSDT'
    note                TEXT,
    created_by          TEXT,                             -- username из auth_users
    created_at          TEXT NOT NULL
);

-- ───────────────────────────────────────────────────────────
-- Лог Bybit health-ping (для /healthz и аналитики)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bybit_health_pings (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ts              TEXT NOT NULL,                        -- ISO8601 UTC
    kind            TEXT NOT NULL,                        -- 'public' | 'signed'
    success         INTEGER NOT NULL,                     -- 0 | 1
    duration_ms     INTEGER,
    error           TEXT
);
CREATE INDEX IF NOT EXISTS idx_bybit_health_ts
    ON bybit_health_pings(ts);
