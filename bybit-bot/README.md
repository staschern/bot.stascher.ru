# Bybit Futures Bot

Автоматизированный бот для торговли бессрочными фьючерсами USDT-perpetual на Bybit
с использованием внешнего источника сигналов и таймфреймом 1h.

**Версия:** 0.1.0-stage1 (Этап 1: каркас).

## Стек
- Ubuntu 20.04 (focal), Apache 2.4.41, **PHP 7.4** (модуль/PHP-FPM), SQLite, Composer 2.x
- Slim 4 (роутер) + Twig 3.4–3.9 (шаблоны) + Guzzle 7 (HTTP) + Monolog 2 (логи) + OTPHP 10 (TOTP 2FA)

## Структура (после Этапа 1)
```
bin/                  CLI и cron-скрипты (cli.php, cron_hourly.php, cron_minute.php, cron_daily.php)
src/Core/             Database, Config, Logger, Lock, CronGuard, EventRecorder, Migrator, Rounding, Bootstrap
src/Strategies/       StrategyInterface, StrategyRegistry, Strategy1/2/3 (скелеты)
src/Exchange/         ExchangeAdapter (контракт)
src/Web/              AppFactory, Auth, AuthMiddleware, Controllers (Auth, Dashboard, Health)
public/               index.php + .htaccess (DocumentRoot Apache)
config/               settings.php, strategies.php, instruments_blacklist.php
data/migrations/      001_initial_schema.sql
templates/            Twig: _layout, login, dashboard
docs/                 spec.md (источник правды), INSTALL.md
tests/                phpunit
```

## Что есть в Этапе 1
- [x] Каркас Slim 4 + Twig (страницы /login, /, /healthz)
- [x] Авторизация bcrypt + TOTP 2FA, защита от brute-force
- [x] Все таблицы БД из spec.md §12 (миграция 001)
- [x] Реестр стратегий со скелетами S1/S2/S3
- [x] Cron-скрипты с двойной защитой (Lock + CronGuard через cron_runs)
- [x] Двухуровневая система настроек (settings + strategy_settings)
- [x] Хелперы: Rounding (с тестами), Logger (Monolog с ротацией), Lock (flock)
- [x] CLI: migrate, auth:init, strategies:list, settings:show
- [x] Документация по установке на VPS

## Что НЕ реализовано в Этапе 1 (по плану — Этап 2)
- ExchangeAdapter (BybitAdapter, BybitTestnetAdapter, PaperAdapter)
- Импорт `signalsHourly.json` и фильтрация сигналов
- Формулы расчёта ордера (entry/TP/SL/qty)
- State machine сделки (PENDING → OPEN → AVERAGED → CLOSED)
- Журнал сделок, журнал событий, страницы настроек, статистика — в UI
- Telegram-алерты

## Установка
См. [docs/INSTALL.md](docs/INSTALL.md).

## Спецификация
Источник правды: [docs/spec.md](docs/spec.md). Любое расхождение между кодом и spec.md —
ошибка, которая исправляется правкой spec.md.
