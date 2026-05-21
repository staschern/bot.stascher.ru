-- v0.7.5: добавить колонку exchange в symbol_aliases для поддержки нескольких бирж.
-- Существующие записи получают exchange='bybit'.
-- Меняем первичный ключ с (source_symbol) на (exchange, source_symbol).
--
-- SQLite не умеет ALTER TABLE для PK — пересоздаём таблицу через _new.
--
-- Идемпотентность: если колонка exchange уже есть — миграция ничего не делает (см. проверку в PHP-обёртке при необходимости).

BEGIN TRANSACTION;

CREATE TABLE IF NOT EXISTS symbol_aliases_new (
    exchange            TEXT NOT NULL DEFAULT 'bybit',     -- 'bybit' | (в будущем другие)
    source_symbol       TEXT NOT NULL,                     -- короткий тикер из сигнала (например 'PEPE')
    bybit_symbol        TEXT NOT NULL,                     -- полное имя фьючерса ('1000PEPEUSDT')
    note                TEXT,
    created_by          TEXT,
    created_at          TEXT NOT NULL,
    updated_at          TEXT,
    PRIMARY KEY (exchange, source_symbol)
);

INSERT INTO symbol_aliases_new (exchange, source_symbol, bybit_symbol, note, created_by, created_at, updated_at)
SELECT 'bybit', source_symbol, bybit_symbol, note, created_by, created_at, created_at
FROM symbol_aliases;

DROP TABLE symbol_aliases;
ALTER TABLE symbol_aliases_new RENAME TO symbol_aliases;

CREATE INDEX IF NOT EXISTS idx_symbol_aliases_exchange ON symbol_aliases(exchange);

COMMIT;
