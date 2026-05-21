-- 016_equity_snapshots.sql
-- v0.9.0-step9 task4: снимки депозита на начало периода для /stats.
-- Хранят значение equity (D0 + накопленный realized PnL) на начало
-- каждой недели/месяца. Используется как deposit_start для расчёта
-- PnL % в /stats.
--
-- account_id NULL = агрегированный snapshot по всем аккаунтам данного режима.
-- period_type = 'week' | 'month'.
-- period_start:
--   * для week — дата понедельника соответствующей недели по UTC, YYYY-MM-DD
--     (используется ISO-неделя, чтобы соответствовать SQL strftime семантике
--      «начало недели = понедельник»; мы храним именно фактический понедельник).
--   * для month — первый день месяца, YYYY-MM-01.
--
-- created_at — момент создания записи (UTC, ISO).

CREATE TABLE IF NOT EXISTS equity_snapshots (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    mode           TEXT    NOT NULL,           -- paper/testnet/live
    account_id     INTEGER NULL,               -- NULL = aggregate across all
    period_type    TEXT    NOT NULL,           -- 'week' | 'month'
    period_start   TEXT    NOT NULL,           -- YYYY-MM-DD
    deposit_usdt   REAL    NOT NULL,           -- equity на начало периода
    source         TEXT    NULL,               -- 'cron_daily' | 'backfill' | 'manual'
    created_at     TEXT    NOT NULL            -- UTC ISO
);

-- Уникальность ключа: (mode, account_id, period_type, period_start).
-- account_id IS NULL — отдельный класс, его уникальность обеспечивается
-- через partial index (SQLite поддерживает с 3.8.0).
CREATE UNIQUE INDEX IF NOT EXISTS idx_eqsnap_unique_acc
    ON equity_snapshots(mode, account_id, period_type, period_start)
    WHERE account_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_eqsnap_unique_agg
    ON equity_snapshots(mode, period_type, period_start)
    WHERE account_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_eqsnap_period
    ON equity_snapshots(mode, period_type, period_start);
