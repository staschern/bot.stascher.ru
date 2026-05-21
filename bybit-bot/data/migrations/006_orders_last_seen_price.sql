-- Миграция 006 — добавляем last_seen_price / last_seen_at в orders
--
-- Цель: для pending conditional ордеров уметь показывать в UI «процент пути»
-- между SL и Entry без дополнительных API-запросов на странице /trades.
-- Поля обновляются PaperAdapter::monitorConditionalOrders() при каждом тике.
-- Для testnet/live ордеров поля можно заполнять реконсилером (опционально, не блокер).

ALTER TABLE orders ADD COLUMN last_seen_price REAL;
ALTER TABLE orders ADD COLUMN last_seen_at    TEXT;
