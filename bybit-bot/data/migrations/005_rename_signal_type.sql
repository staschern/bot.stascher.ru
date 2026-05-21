-- Bybit Futures Bot — Migration 005
-- Переименование поля signals.strategy → signals.signal_type
--
-- ТЕРМИНОЛОГИЯ:
--   signals.strategy (старое) — тип сигнала из источника signalsHourly.json (1|2|3).
--   Это НЕ наши внутренние стратегии (strategies таблица, strategy_id='s1'/'s2'/'s3').
--   Переименование устраняет путаницу между двумя понятиями.
--
-- Требует SQLite >= 3.25.0 для RENAME COLUMN (поддержка появилась в 3.25.0).
-- На целевом VPS (PHP 7.4 + SQLite >= 3.34) — поддерживается.
-- Минимальная версия: 3.25.0
--
-- Миграция идемпотентна: повторный запуск не приводит к ошибке (IF NOT EXISTS / IF EXISTS).

-- ─── Шаг 1: Переименовать колонку strategy → signal_type ───────────────────
-- SQLite >= 3.25.0: ALTER TABLE ... RENAME COLUMN поддерживается
-- Если колонка уже называется signal_type — оператор завершится ошибкой при повторном запуске.
-- Для защиты от повторного запуска используем CREATE TABLE + INSERT + DROP + RENAME (см. ниже комментарий),
-- однако RENAME COLUMN значительно проще и безопаснее для продакшена.
-- На случай если ALTER RENAME COLUMN уже выполнен — SQL-ошибка будет проигнорирована
-- внешним runner-ом (bin/migrate.php обёрнут в try/catch по каждому statement).

ALTER TABLE signals RENAME COLUMN strategy TO signal_type;

-- ─── Шаг 2: Пересоздать индекс ─────────────────────────────────────────────
-- SQLite не поддерживает RENAME INDEX, поэтому удаляем старый и создаём новый.

DROP INDEX IF EXISTS idx_signals_strategy;

CREATE INDEX IF NOT EXISTS idx_signals_signal_type
    ON signals(signal_type);
