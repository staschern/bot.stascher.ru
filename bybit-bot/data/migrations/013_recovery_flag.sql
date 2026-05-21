-- v0.8.0.12: восстановление отменённых conditional ордеров.
--
-- ignore_sl_until_open: если 1 — реконсайлер/cron не отменяет conditional
-- при пересечении SL до открытия позиции. Сбрасывается в 0 при переходе
-- trade в OPEN. По умолчанию 0 — обычное поведение.
--
-- recovered_from_trade_id: ID исходного отменённого трейда, копия которого
-- была создана. NULL для обычных трейдов.
--
-- recovered_at: момент восстановления (UTC ISO).

ALTER TABLE trades ADD COLUMN ignore_sl_until_open INTEGER NOT NULL DEFAULT 0;
ALTER TABLE trades ADD COLUMN recovered_from_trade_id INTEGER NULL;
ALTER TABLE trades ADD COLUMN recovered_at TEXT NULL;
