-- Bybit Futures Bot — Этап 3: единая колонка exchange в orders/positions
-- Миграция 004. SQLite-идемпотентная через CREATE TABLE IF NOT EXISTS guard-таблицы.
-- Колонки добавляются через отдельные ALTER TABLE (Migrator выполняет весь файл за один exec).
-- ВАЖНО: при повторном применении — колонки уже есть → exec() упадёт.
-- Этот файл применяется РОВНО ОДИН РАЗ (Migrator хранит версии в schema_migrations).
-- Поэтому идемпотентность не требуется для ALTER TABLE — только для INSERT/UPDATE.
-- См. spec.md §13a (v0.5.0).

-- ───────────────────────────────────────────────────────────────────
-- 1. Добавить колонки exchange + sl_price в orders
-- ───────────────────────────────────────────────────────────────────
ALTER TABLE orders ADD COLUMN exchange TEXT NOT NULL DEFAULT 'paper';
ALTER TABLE orders ADD COLUMN sl_price REAL;

-- ───────────────────────────────────────────────────────────────────
-- 2. Добавить колонку exchange в positions + поля SL/TP/trailing
-- ───────────────────────────────────────────────────────────────────
ALTER TABLE positions ADD COLUMN exchange TEXT NOT NULL DEFAULT 'paper';
ALTER TABLE positions ADD COLUMN sl_price REAL;
ALTER TABLE positions ADD COLUMN tp_price REAL;
ALTER TABLE positions ADD COLUMN trailing_pct REAL;
ALTER TABLE positions ADD COLUMN trailing_trigger_price REAL;

-- ───────────────────────────────────────────────────────────────────
-- 3. Синхронизировать флаг paper → exchange
-- ───────────────────────────────────────────────────────────────────
UPDATE orders SET exchange = 'paper'   WHERE paper = 1;
UPDATE orders SET exchange = 'testnet' WHERE paper = 0;
UPDATE positions SET exchange = 'paper'   WHERE paper = 1;
UPDATE positions SET exchange = 'testnet' WHERE paper = 0;

-- ───────────────────────────────────────────────────────────────────
-- 4. Перенос данных paper_orders → orders
-- ВАЖНО: реальная схема paper_orders (из миграции 001) имеет только:
-- id, trade_id, kind, side, trigger_price, limit_price, qty, status, filled_at, filled_price
-- Колонок created_at/placed_at там нет, поэтому placed_at='now' для всех мигрируемых.
-- ───────────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO orders
    (trade_id, purpose, side, order_type, qty, price, trigger_price, reduce_only,
     bybit_order_link_id, status, placed_at, filled_at, paper, exchange)
SELECT
    po.trade_id,
    CASE po.kind
        WHEN 'conditional_open' THEN 'entry_conditional'
        WHEN 'part_tp'          THEN 'tp_partial'
        WHEN 'avg'              THEN 'avg'
        WHEN 'sl'               THEN 'sl'
        WHEN 'trailing'         THEN 'trailing'
        ELSE COALESCE(po.kind, 'entry_conditional')
    END,
    COALESCE(po.side, 'Buy'),
    'Conditional',
    po.qty,
    po.limit_price,
    po.trigger_price,
    0,
    'paper-migr-' || CAST(po.id AS TEXT),
    CASE po.status
        WHEN 'pending'   THEN 'placed'
        WHEN 'filled'    THEN 'filled'
        WHEN 'cancelled' THEN 'cancelled'
        ELSE COALESCE(po.status, 'placed')
    END,
    datetime('now'),
    po.filled_at,
    1,
    'paper'
FROM paper_orders po
WHERE NOT EXISTS (
    SELECT 1 FROM orders o
    WHERE o.bybit_order_link_id = 'paper-migr-' || CAST(po.id AS TEXT)
);

-- ───────────────────────────────────────────────────────────────────
-- 5. Перенести данные paper_positions → positions (идемпотентно)
-- ───────────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO positions
    (trade_id, symbol, side, qty, qty_initial, avg_entry_price, leverage, margin_mode,
     sl_price, tp_price, trailing_pct, trailing_trigger_price,
     opened_at, closed_at, close_reason, realised_pnl_usdt, paper, exchange)
SELECT
    pp.trade_id, pp.symbol, pp.side, pp.qty, pp.qty_initial,
    pp.avg_entry_price, pp.leverage, pp.margin_mode,
    pp.sl_price, pp.tp_price, pp.trailing_pct, pp.trailing_trigger_price,
    pp.opened_at, pp.closed_at, pp.close_reason, pp.realised_pnl_usdt,
    1, 'paper'
FROM paper_positions pp
WHERE NOT EXISTS (
    SELECT 1 FROM positions p WHERE p.trade_id = pp.trade_id AND p.paper = 1
);

-- ───────────────────────────────────────────────────────────────────
-- 6. Индексы
-- ───────────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_orders_exchange ON orders(exchange);
CREATE INDEX IF NOT EXISTS idx_positions_exchange ON positions(exchange);
CREATE INDEX IF NOT EXISTS idx_orders_pending_conditional ON orders(status, purpose);
