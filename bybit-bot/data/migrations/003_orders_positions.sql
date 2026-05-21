-- Bybit Futures Bot — Этап 2 item 3: ордера, позиции, signal_id
-- См. spec.md §3.3, §6, §12.

-- ───────────────────────────────────────────────────────────
-- Привязка сигнала к сделке (добавляем колонку в существующую таблицу)
-- ───────────────────────────────────────────────────────────
ALTER TABLE trades ADD COLUMN signal_id INTEGER REFERENCES signals(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_trades_signal_id ON trades(signal_id);

-- ───────────────────────────────────────────────────────────
-- Ордера (state-machine ордеров в Bybit + paper-симуляции)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    trade_id            INTEGER NOT NULL REFERENCES trades(id) ON DELETE CASCADE,

    -- Роль ордера в жизненном цикле сделки
    purpose             TEXT NOT NULL,        -- 'entry_conditional'|'sl'|'tp_partial'|'avg'|'trailing'

    side                TEXT NOT NULL,        -- 'Buy'|'Sell'
    order_type          TEXT NOT NULL,        -- 'Market'|'Limit'|'Conditional'

    qty                 REAL NOT NULL,
    price               REAL,                 -- для Limit-ордеров
    trigger_price       REAL,                 -- для conditional/stop-ордеров

    reduce_only         INTEGER NOT NULL DEFAULT 0,  -- 0|1

    -- Идентификаторы на бирже
    bybit_order_id      TEXT,                 -- NULL для paper до исполнения
    bybit_order_link_id TEXT NOT NULL,        -- генерируется локально (idempotency)

    -- Статус ордера
    status              TEXT NOT NULL,        -- 'pending'|'placed'|'filled'|'cancelled'|'rejected'

    -- Временны́е метки
    placed_at           TEXT,
    filled_at           TEXT,
    cancelled_at        TEXT,

    -- Сырой ответ Bybit (для разбора инцидентов)
    raw_response_json   TEXT,

    -- Флаг симуляции
    paper               INTEGER NOT NULL DEFAULT 0   -- 0=реальный, 1=paper
);

CREATE INDEX IF NOT EXISTS idx_orders_trade_id        ON orders(trade_id);
CREATE INDEX IF NOT EXISTS idx_orders_trade_status    ON orders(trade_id, status);
CREATE INDEX IF NOT EXISTS idx_orders_status_paper    ON orders(status, paper);
CREATE INDEX IF NOT EXISTS idx_orders_link_id         ON orders(bybit_order_link_id);

-- ───────────────────────────────────────────────────────────
-- Позиции (соответствие сделке в Bybit + paper-симуляции)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS positions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    trade_id        INTEGER NOT NULL REFERENCES trades(id) ON DELETE CASCADE,

    symbol          TEXT NOT NULL,
    side            TEXT NOT NULL,            -- 'Buy'|'Sell' (Bybit convention)

    -- Объём позиции
    qty             REAL NOT NULL,            -- текущий qty
    qty_initial     REAL NOT NULL,            -- qty на момент открытия (для аналитики)

    avg_entry_price REAL NOT NULL,

    leverage        INTEGER NOT NULL,
    margin_mode     TEXT NOT NULL DEFAULT 'cross',  -- 'cross'|'isolated'

    -- Временны́е метки
    opened_at       TEXT NOT NULL,
    closed_at       TEXT,

    -- Причина закрытия
    close_reason    TEXT,   -- 'tp'|'sl'|'trailing'|'manual'|'avg_then_tp'|'expired'|etc

    -- Итоги
    realised_pnl_usdt   REAL,

    -- Флаг симуляции
    paper               INTEGER NOT NULL DEFAULT 0,  -- 0=реальный, 1=paper

    -- Уникальность: одна позиция на сделку
    UNIQUE (trade_id)
);

CREATE INDEX IF NOT EXISTS idx_positions_symbol_open ON positions(symbol, closed_at);
CREATE INDEX IF NOT EXISTS idx_positions_paper       ON positions(paper, closed_at);
CREATE INDEX IF NOT EXISTS idx_positions_trade       ON positions(trade_id);

-- ───────────────────────────────────────────────────────────
-- paper_positions — таблица симулированных позиций
-- (дополняет paper_orders из миграции 001)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS paper_positions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    trade_id        INTEGER NOT NULL REFERENCES trades(id) ON DELETE CASCADE,
    symbol          TEXT NOT NULL,
    side            TEXT NOT NULL,            -- 'Buy'|'Sell'

    qty             REAL NOT NULL,            -- текущий qty
    qty_initial     REAL NOT NULL,

    avg_entry_price REAL NOT NULL,
    leverage        INTEGER NOT NULL,
    margin_mode     TEXT NOT NULL DEFAULT 'cross',

    -- SL/TP/Trailing, активные в данный момент
    sl_price        REAL,
    tp_price        REAL,
    trailing_pct    REAL,
    trailing_trigger_price REAL,

    opened_at       TEXT NOT NULL,
    closed_at       TEXT,
    close_reason    TEXT,
    realised_pnl_usdt REAL,

    UNIQUE (trade_id)
);

CREATE INDEX IF NOT EXISTS idx_paper_positions_symbol ON paper_positions(symbol, closed_at);
CREATE INDEX IF NOT EXISTS idx_paper_positions_trade  ON paper_positions(trade_id);
