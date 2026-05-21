-- v0.9.0: Multi-account Bybit.
--
-- Несколько пар ключей (субаккаунты Bybit). Каждый аккаунт:
--   * имеет произвольное имя (UNIQUE);
--   * может быть включён/выключен (enabled) — флаг влияет ТОЛЬКО на открытие
--     НОВЫХ ордеров. Все уже открытые позиции сопровождаются до закрытия
--     независимо от enabled.
--   * может быть архивирован (archived_at IS NOT NULL) — soft-delete,
--     разрешён только при отсутствии открытых позиций по этому аккаунту.
--
-- Секреты (api_key/api_secret) НЕ хранятся в БД — лежат в файлах
--   data/secrets/account_{id}.php (chmod 0600).
--
-- Каждый trade/order/position помечается account_id (FK) + денормализованный
-- account_name (снимок имени на момент открытия — переименование аккаунта
-- НЕ меняет историю).

CREATE TABLE IF NOT EXISTS bybit_accounts (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    name           TEXT    NOT NULL UNIQUE,
    network        TEXT    NOT NULL,                  -- 'live' | 'testnet'
    enabled        INTEGER NOT NULL DEFAULT 1,        -- 0|1
    api_key_mask   TEXT    NOT NULL DEFAULT '',       -- последние 4 символа для UI
    archived_at    TEXT    NULL,                      -- ISO UTC, soft-delete
    created_at     TEXT    NOT NULL,
    updated_at     TEXT    NOT NULL,
    CHECK (network IN ('live','testnet')),
    CHECK (enabled IN (0,1))
);

CREATE INDEX IF NOT EXISTS idx_bybit_accounts_enabled
    ON bybit_accounts(enabled, archived_at);

-- Привязка trade/order/position к аккаунту.
-- NULL для paper-режима (единый встроенный paper-аккаунт без записи в bybit_accounts).
-- Денормализованный account_name = снимок имени на момент создания.
ALTER TABLE trades    ADD COLUMN account_id   INTEGER NULL;
ALTER TABLE trades    ADD COLUMN account_name TEXT    NULL;
ALTER TABLE orders    ADD COLUMN account_id   INTEGER NULL;
ALTER TABLE positions ADD COLUMN account_id   INTEGER NULL;

CREATE INDEX IF NOT EXISTS idx_trades_account_status
    ON trades(account_id, status);
CREATE INDEX IF NOT EXISTS idx_orders_account
    ON orders(account_id);
CREATE INDEX IF NOT EXISTS idx_positions_account
    ON positions(account_id);
