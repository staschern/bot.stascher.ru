-- Миграция 007 — добавляем last_price / last_price_at в positions
--
-- Цель: для открытых позиций уметь показывать в UI текущий PnL (USDT + %)
-- и прогресс-бар (SL → BE → trigger_trailing) без дополнительных API-запросов
-- на странице /trades.
--
-- Поля обновляются PaperAdapter::tickPositions() при каждом тике cron_minute.
-- Для testnet/live позиций поля заполняются BybitAdapter::reconcilePositions
-- (будет добавлено отдельно при работе с testnet/live).

ALTER TABLE positions ADD COLUMN last_price    REAL;
ALTER TABLE positions ADD COLUMN last_price_at TEXT;
