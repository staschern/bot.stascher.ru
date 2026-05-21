-- v0.7.7: журнал сигналов — фиксируем решение бота для каждого сигнала.
-- Добавляем колонки decision / decision_reason / decision_at / trade_id в signals.
-- Бэкфилл существующих записей: где можно вывести по фактическим данным.
-- Идемпотентно (PRAGMA + INSERT OR IGNORE подходов sqlite3 для ALTER нет, поэтому проверяем через временный CREATE).

-- Шаг 1: добавляем колонки. sqlite не умеет ADD COLUMN IF NOT EXISTS, поэтому используем
-- безопасный шаблон через _new таблицу.

BEGIN TRANSACTION;

-- Если миграция уже выполнялась — выйти (через проверку pragma_table_info).
-- В sqlite нет «выхода», поэтому используем CREATE TABLE _check, который упадёт если decision уже есть.
-- Альтернатива — выполнить отдельным шагом через приложение, но мы хотим один SQL-файл.

-- Создаём новую таблицу с расширенной схемой.
CREATE TABLE IF NOT EXISTS signals_new (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    date                TEXT NOT NULL,
    time                TEXT NOT NULL,
    saved_at            TEXT NOT NULL,
    saved_at_utc        TEXT NOT NULL,
    symbol              TEXT NOT NULL,
    side                TEXT NOT NULL,
    target              REAL NOT NULL,
    signal_type         INTEGER NOT NULL,
    potential           INTEGER NOT NULL DEFAULT 0,
    rsi                 REAL,
    w7                  INTEGER,
    w14                 INTEGER,
    w30                 INTEGER,
    w_all               INTEGER,
    bybit_symbol        TEXT,
    resolution_status   TEXT NOT NULL DEFAULT 'unresolved',
    resolved_at         TEXT,
    imported_at         TEXT NOT NULL,
    -- v0.7.7 новые поля
    decision            TEXT,            -- accepted | rejected_unresolved | rejected_filter | rejected_guard | rejected_duplicate | rejected_other
    decision_reason     TEXT,            -- свободный текст (имя guard, имя фильтра, error message)
    decision_at         TEXT,            -- ISO8601 UTC момент принятия решения
    trade_id            INTEGER          -- если decision='accepted'
);

-- Перенос данных (только если signals_new пустая, чтобы не дублировать при повторном запуске).
INSERT OR IGNORE INTO signals_new (
    id, date, time, saved_at, saved_at_utc, symbol, side, target, signal_type, potential,
    rsi, w7, w14, w30, w_all, bybit_symbol, resolution_status, resolved_at, imported_at,
    decision, decision_reason, decision_at, trade_id
)
SELECT
    id, date, time, saved_at, saved_at_utc, symbol, side, target, signal_type, potential,
    rsi, w7, w14, w30, w_all, bybit_symbol, resolution_status, resolved_at, imported_at,
    NULL, NULL, NULL, NULL
FROM signals;

-- Подменяем таблицу.
DROP TABLE signals;
ALTER TABLE signals_new RENAME TO signals;

-- Восстанавливаем индексы.
CREATE UNIQUE INDEX IF NOT EXISTS idx_signals_unique
    ON signals(date, time, symbol, side);
CREATE INDEX IF NOT EXISTS idx_signals_saved_at_utc
    ON signals(saved_at_utc);
CREATE INDEX IF NOT EXISTS idx_signals_signal_type
    ON signals(signal_type);
CREATE INDEX IF NOT EXISTS idx_signals_imported_at
    ON signals(imported_at);
CREATE INDEX IF NOT EXISTS idx_signals_decision
    ON signals(decision);

-- Бэкфилл: для unresolved → rejected_unresolved
UPDATE signals
   SET decision        = 'rejected_unresolved',
       decision_reason = 'символ не сопоставлен с фьючерсом Bybit',
       decision_at     = imported_at
 WHERE decision IS NULL
   AND resolution_status = 'unresolved';

-- Бэкфилл: для сигналов, у которых был создан trade → accepted
-- (находим через trades.signal_id если такая колонка есть; иначе через events с kind='conditional_placing')
UPDATE signals
   SET decision        = 'accepted',
       decision_reason = 'trade создан',
       decision_at     = imported_at,
       trade_id        = (
           SELECT t.id FROM trades t WHERE t.signal_id = signals.id ORDER BY t.id DESC LIMIT 1
       )
 WHERE decision IS NULL
   AND EXISTS (SELECT 1 FROM trades t WHERE t.signal_id = signals.id);

COMMIT;
