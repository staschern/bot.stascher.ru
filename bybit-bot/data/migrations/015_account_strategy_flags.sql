-- v0.9.0-step7: Per-strategy флаги для аккаунтов Bybit.
--
-- Раньше колонка bybit_accounts.enabled управляла участием аккаунта во ВСЕХ
-- стратегиях одновременно. Теперь:
--   * enabled        — master switch (выкл = аккаунт неактивен везде, не учитывается ни в fan-out, ни в s2-мультиселекте);
--   * s1_enabled     — участвует ли аккаунт в авто-fan-out стратегии s1 (cron_hourly);
--   * s2_enabled     — показывается ли аккаунт в форме ручного открытия s2;
--   * s3_enabled     — на будущее (стратегия s3); сейчас тоже учитывается там где её фильтр применим.
--
-- Дефолт = 1 для всех существующих и новых аккаунтов. Поведение остаётся прежним
-- до тех пор, пока пользователь не снимет какой-то флаг руками.

ALTER TABLE bybit_accounts ADD COLUMN s1_enabled INTEGER NOT NULL DEFAULT 1;
ALTER TABLE bybit_accounts ADD COLUMN s2_enabled INTEGER NOT NULL DEFAULT 1;
ALTER TABLE bybit_accounts ADD COLUMN s3_enabled INTEGER NOT NULL DEFAULT 1;
