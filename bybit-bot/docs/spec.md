# Bybit Futures Bot — Техническое задание (spec.md)

> Версия: 0.7.0 (UX, PnL открытых позиций, эквити-виджет — частичная реализация ТЗ 14.5-14.6)
> Источник правды для всех этапов реализации. Любое расхождение между кодом и этим документом считается ошибкой документации или кода — обсуждается и фиксируется правкой `spec.md`.
> Все `ASSUMPTION` пометки в §3–§9 закрыты в v0.4.0.

## Журнал изменений
- **v0.7.0 (2026-05)** — Расширение страницы `/trades` (в работе, шаги 1-2 из 5 блоков плана):
  - **Блок A (UX)**: дефолтный фильтр статуса — `active` (`OPEN` + `PENDING_CONDITIONAL`), вместо «всё». В селекте добавлены пункты «активные (open+pending)» (default) и «все». Ссылка «сбросить» ведёт на `/trades?status=all`. `<meta http-equiv="refresh">` убран — заменён JS-таймером, который раз в секунду показывает обратный отсчёт и **пропускает reload**, если: (a) аккордеон ручного ввода раскрыт или (b) в любом из 4 полей формы есть значение. После 60 сек, если условие удержания не выполняется, происходит `window.location.reload()`. Затронуты: `src/Web/Controllers/TradesController.php`, `templates/trades.twig`.
  - **Блок B (PnL + эквити)**: 
    - Миграция 007 добавляет колонки `positions.last_price REAL` + `positions.last_price_at TEXT`. `PaperAdapter::tickPositions()` в начале цикла обновляет эти поля свежей `getCurrentPrice($symbol)` — без дополнительных API-запросов (цена и так нужна для проверки SL/TP). 
    - В `TradesController` для OPEN-сделок считается **плавающий PnL по первоначальному лоту** (без учёта усреднений): `pnl_usdt = (last_price - entry_real) * qty_initial * sign`, `pnl_pct = pnl_usdt / margin_initial * 100` где `margin_initial = (entry_real * qty_initial) / leverage`. **Прогресс-бар (3 состояния)**: (1) `before_be` — цена не дошла до точки безубытка, **красный** бар, % = пройденного пути от SL до BE; (2) `to_trigger` — цена выше/ниже BE (в зависимости от стороны), но трейлинг ещё не активирован, **зелёный** бар, % = пути от BE до `trailing_trigger_price`; (3) `trailing_active` — цена пересекла trigger, трейлинг активен, **ярко-зелёный** бар (та же геометрия, что состояние 2, но цвет насыщеннее, % может быть > 100). Точка BE: если есть `trades.break_even_price` (после усреднения по §7.1) — берём её, иначе вычисляем как `entry_real * (1 + sign * 2*taker_fee_pct/100)`.
    - Новый сервис `src/Trade/EquityService.php` считает эквити по формуле ТЗ §14.5-14.6. Для paper: `D0 = paper_initial_deposit_usdt`, `wallet = D0 - margin_open + realized`, `floating = sum((cur-entry)*qty1*sign - close_fee)` по открытым, `equity = wallet + floating`. Для testnet/live: `D0` из первой записи `deposit_snapshots(mode=...)`. **Цветовая раскраска** по 5 порогам (Текущий / D0): `≥0.8 green`, `0.5..0.8 light-green`, `0.3..0.5 yellow`, `0.1..0.3 light-red`, `<0.1 red`. Виджет эквити на `/trades` (поле над аккордеоном ручного ввода) показывает Equity / D0 / Wallet / Floating PnL / Realized / Margin (open) / % от D0.
    - Затронуты: `data/migrations/007_positions_last_price.sql` (новая), `src/Exchange/PaperAdapter.php` (одна вставка в tickPositions), `src/Trade/EquityService.php` (новый), `src/Web/Controllers/TradesController.php` (расширенный SELECT + расчёт PnL + EquityService), `templates/trades.twig` (виджет + новая колонка PnL + CSS).
  - **Остальные блоки (C/D/E)**: история событий + signal_decisions, периодические результаты, маппинг тикер↔биржа — следующими шагами.
- **v0.6.0 (2026-05)** — Strategy 2: ручной ввод условных ордеров через UI. Новый сервис `src/Trade/ManualOrderService.php` принимает `{symbol, side, entry, sl, tp}`, нормализует символ (UPPER + добавление `USDT` если нет суффикса USDT/USDC/USD/PERP), валидирует порядок цен (long: TP > Entry > SL; short: SL > Entry > TP), проверяет существование символа на бирже через `adapter->getInstrumentInfo()` (для paper — на mainnet через `baseUrlPublic`, см. v0.5.3), считает leverage и qty по тем же формулам, что Strategy 1 §5.4 (`base_lot = 0.01 × deposit_anchor`, `qty = unleveraged / leverage / entry`), создаёт запись в `trades` с `strategy_id='s2'` и `signal_id=NULL`, ставит conditional через тот же `adapter->placeConditional()` с `order_link_id = s2-{tradeId}-entry-{nano}`. Сопровождение после срабатывания идёт через **те же** хуки `Strategy1::onPositionOpened/Averaged` (по `strategy_id` в `trades`); никакого дублирования логики не требуется. Новый контроллер `src/Web/Controllers/ManualController.php` обслуживает: `POST /manual/submit` (форма → ManualOrderService::submit → flash через cookie `manual_flash` → redirect на `/trades`); `GET /manual/check-symbol?symbol=...` (async проверка для UI: возвращает JSON `{ok, normalized, last_price, max_leverage, tick_size, qty_step, qty_min}`). UI: над таблицей `/trades` добавлен `<details>`-аккордеон «Ручной ввод условного ордера (s2)», свёрнут по умолчанию; поля Symbol (с `<datalist>` известных тикеров из `SELECT DISTINCT symbol FROM trades`), Side (long/short), Entry, SL, TP. JS-валидация в реальном времени: при вводе символа дебаунс 400 мс → `fetch('/manual/check-symbol')`, подсветка `.invalid` (красная рамка) при нарушении порядка цен, кнопка «Отправить» disabled пока всё не валидно. При повторной загрузке страницы flash-cookie разворачивается в `.flash-ok`/`.flash-error` баннер, при ошибке аккордеон снова открыт. Затронуты: `src/Trade/ManualOrderService.php` (новый), `src/Web/Controllers/ManualController.php` (новый), `src/Web/AppFactory.php` (роуты `manual_submit`, `manual_check_symbol`), `src/Web/Controllers/TradesController.php` (передача `known_symbols`), `templates/trades.twig` (форма + JS + flash). Терминология: `signals.signal_type` (1/2/3) — это тип сигнала из источника, обрабатывается **только** Strategy 1; `trades.strategy_id` ('s1'/'s2') — наш внутренний маркер происхождения трейда (s1 = автоматически из json-источника, s2 = ручной ввод пользователя). Stub-классы `Strategies\Strategy2\Strategy2` и `Strategy3\Strategy3` в реестре стратегий не используются (в `config/strategies.php` они выключены/отсутствуют) — фактическая ручная логика реализована через `ManualOrderService`, минуя интерфейс `StrategyInterface`. Несколько pending-трейдов по одному symbol с `strategy_id='s2'` разрешены — пользователь может выставить разнонаправленные «вилки» двумя последовательными отправками; функция `cancelOldConditionalForSymbol` в `cron_hourly` фильтрует только по `strategy_id='s1'`, поэтому s2-ордера от неё не страдают. Маппинг тикер↔биржа (запрошенный пункт 1) — отложен на следующую версию.
- **v0.5.4 (2026-05)** — UI улучшения страницы `/trades`. (1) Добавлен Twig-фильтр `|msk` (в `Web\AppFactory`) — конвертирует UTC ISO/«Y-m-d H:i:s» время в Europe/Moscow. Применён в `trades.twig` и `trade_detail.twig` всезде вместо «сырого» slice. Был баг: времена в UI отображались в UTC, хотя пользователь в MSK. (2) В таблицу `/trades` добавлена колонка **Placed** — время постановки entry_conditional (из `orders.placed_at`, JOIN в `TradesController::index()`). (3) Для trades со статусом `PENDING_CONDITIONAL` теперь отображаются Entry/Qty/SL/TP из entry-ордера (были «—» до открытия позиции) с серым цветом (`.pending-value`); после открытия белым из `positions`. (4) Колонка PnL для pending теперь показывает **«% пути SL→Entry»** + мини-прогрессбар серым цветом. Формула: short — `(sl - price) / (sl - trigger) * 100`, long — `(price - sl) / (trigger - sl) * 100`. 0% = рядом с SL (близко к отмене по правилу «цена пробила SL»), 100% = рядом с trigger (выстрел). >100% — жёлтый индикатор (сработавший, ждём фиксации позиции), <0% — красный (за SL, отменится). (5) Миграция 006 добавляет `orders.last_seen_price REAL` + `orders.last_seen_at TEXT`. `PaperAdapter::monitorConditionalOrders()` при каждой минутной итерации обновляет эти поля для всех отслеживаемых conditional — без дополнительной нагрузки на API (цена и так запрашивается для проверки триггера). UI-страница читает только из БД, без вызовов биржи. Затронуты: `src/Web/AppFactory.php`, `src/Web/Controllers/TradesController.php`, `src/Exchange/PaperAdapter.php`, `templates/trades.twig`, `templates/trade_detail.twig`, `data/migrations/006_orders_last_seen_price.sql`.
- **v0.5.3 (2026-05)** — Stage 3.1 hotfix: разделение базовых URL Bybit API на **public** (всегда mainnet) и **private** (по `mode`). Корневая причина — в paper и testnet режимах `Bybit\Client` ходил на `api-testnet.bybit.com` для всех эндпоинтов, включая публичные `kline/tickers/instruments`. На testnet HBARUSDT (и другие низколиквидные пары) живёт со «своими» ценами и широкой амплитудой ~10–60% — стратегия 1 считала по этим данным `entry_ref`/`amplitude_pct` и выставляла conditional ордера на ценах, не имеющих отношения к реальному рынку. Зафиксированный кейс: HBARUSDT 10.05.2026 15:01 MSK — расчётный entry по mainnet ≈ 0.09394 (как ожидалось), фактический по testnet 0.08989 (рассогласование 4.3%, отмена через 62 секунды по правилу «цена пробила SL»). Решение: `Bybit\Client` теперь хранит два baseUrl: `baseUrlPublic` (всегда mainnet, для `get()`/публичных запросов) и `baseUrlPrivate` (по mode, для `getSigned()`/`postSigned()`). Подпись для public-запросов не нужна — поэтому отсутствие mainnet-ключей не блокирует чтение свечей в paper и testnet режимах. Затронуты: `src/Bybit/Client.php` (раздельные URL, конструктор `(public, private, signer, network, debug)`, выбор baseUrl в `request()` по флагу `$signed`); `src/Exchange/BybitAdapter.php` (`buildClient()` собирает оба URL). Поведение публичных эндпоинтов: `getKline`, `getTickers24h`, `getInstrumentInfo`, `getServerTime`, `getFundingRate`, `getInstruments` (полный список) — всегда mainnet. Подписанные `placeOrder`, `cancelOrder`, `getPositions`, `getWalletBalance`, `setLeverage` и т. п. — по mode (paper/testnet → testnet; live → mainnet).
- **v0.5.2 (2026-05)** — Stage 3.1 hotfix: финальная починка UI checkboxes. Корневая причина — PHP исторически конвертирует точки в именах POST-полей в подчёркивания (`daily_drawdown.enabled` → `daily_drawdown_enabled` в `$_POST`/`getParsedBody()`). Из-за этого save() видел все bool-поля как `<MISSING>` и записывал `0` в БД при каждом сохранении. Решение — нормализатор `$dotKeys` в начале `SettingsController::save()`, который восстанавливает оригинальные ключи с точкой. Это аккуратнее, чем переименование полей в шаблоне (ключи в БД остаются каноничными `name.subkey`). Затронуты ключи: `daily_drawdown.{enabled,pct}`, `signal_upper_cap.{enabled,pct}`, `long_short_balance.{enabled,max_share_pct,min_total_to_check}`, `funding_filter.{enabled,extreme_pct}`, `min_lot_overshoot.pct`. Документировано как обязательное правило: если в БД есть ключи с точкой и они выводятся в HTML-форму — нужен dotKeys-маппинг при чтении POST.
- **v0.5.1 (2026-05)** — Stage 3.1 багфиксы: (1) Strategy 1 теперь обрабатывает все signal_type из источника (1, 2, 3) — убран ошибочный фильтр `AND s.strategy = 1` в `loadFreshSignals()`. (2) Исправлен баг UI checkboxes (попытка №1, **не довела до конца** — см. v0.5.2): 4 флага защит и флаги стратегий используют hidden+checkbox трюк. (3) Поле `signals.strategy` переименовано в `signals.signal_type` (миграция 005) — терминологическое уточнение: signal_type из источника ≠ наши внутренние Strategy1/2/3. (4) Нормализация old values при сравнении булевых настроек (true/false → 1/0).
- **v0.5.0 (2026-05)** — Stage 3: BybitAdapter (testnet/live), AdapterFactory, 4 режима (`paper`/`testnet`/`live`/`pause`), Web UI (Settings + Trades), миграция 004. Подробнее см. §13a и §14 (обновлено). Добавлен `amendOrder` в `Client` (POST `/v5/order/amend`). Унификация таблиц `orders`/`positions`: добавлены колонки `exchange` (`'paper'`/`'testnet'`/`'live'`) и `sl_price`; `paper_orders`/`paper_positions` сохранены для обратной совместимости. CLI-команды: `bybit:mode get/set`, `paper:close-all`, `reconcile:now [exchange]`, `exchange:status`.
- **v0.4.0 (2026-05)** — Этап 2 item 3: Strategy 1 paper-режим. Закрыты все ASSUMPTION из §3–§9. Реализованы: `src/Strategies/Strategy1/Strategy1.php` (полная логика collectAutoIntents/buildConditionalOrder/onPositionOpened/onPositionAveraged), `src/Exchange/PaperAdapter.php` (tick-симулятор), `src/Guards/` (6 классов защит), расширен `src/Bybit/Client.php` (getKline, getTickers24h, getWalletBalance, placeOrder, cancelOrder, setTradingStop, setLeverage, switchMarginMode, getPositions, getOpenOrders, getExecutions, getFundingRate). Миграция `003_orders_positions.sql`: таблицы `orders`, `positions`, `paper_positions`, колонка `signal_id` в `trades`. Обновлены `bin/cron_hourly.php` (реальный запуск стратегий), `bin/cron_minute.php` (tick-симулятор + onPositionOpened/Averaged), `bin/cli.php` (+8 новых команд). Исправления по Уточнениям: delta_pct_of_amplitude=0.08, deposit_anchor=totalWalletBalance, paper_initial=300, freshness=date+hour MSK, avg_qty=current_qty×2×coef, стейблкоины-список, margin_mode=cross.
- **v0.3.0 (09.05.2026)** — Этап 2 (часть 1): Bybit V5 API клиент (`src/Bybit/`) с HMAC-SHA256 подписью и retry-логикой; импортёр сигналов (`src/Signals/`) с фильтром `saved_at_utc > MAX(saved_at_utc)`; кеш инструментов и резолвер тикеров (приоритет: `symbol_aliases` → `{symbol}USDT` → префиксы 10/100/1000/10000); хранение сигналов 30 дней (`cron_daily` cleanup). OTPHP API исправлен: `TOTP::create()` (10.x) вместо `TOTP::generate()` / `TOTP::createFromSecret()` (только в 11.x). Добавлены таблицы: `signals`, `bybit_instruments`, `symbol_aliases`, `bybit_health_pings`. CLI: `bybit:server-time`, `bybit:auth-check`, `bybit:refresh-instruments`, `signals:import`, `signals:resolve`, `signals:show`, `signals:unresolved`, `symbols:alias`. /healthz расширен Bybit-проверкой (опционально, порог 2 ч).
- **v0.2.1 (09.05.2026)** — целевая версия PHP понижена с 8.3 до **7.4** для совместимости с действующим окружением VPS (PPA `ondrej/php` удалил пакеты PHP 8.x для focal, sury.org возвращает 418, а сборка из исходников и Docker отвергнуты по требованию минимизации изменений). Все 8.x-конструкции (`readonly`, `match`, named args, `str_contains`/`str_starts_with`/`str_ends_with`, constructor property promotion, `enum`, nullsafe `?->`) переписаны под 7.4. Версии библиотек выбраны совместимыми с 7.4 (Slim 4 / Twig 3.4–3.9 / Monolog 2.x / OTPHP 10.x / phpunit 9.6).
- **v0.2 (08.05.2026)** — модульные стратегии (S1/S2/S3), двухуровневая система настроек (глобальные + per-strategy override), уточнения по копированию `signalsHourly.json`, формула `qty` подтверждена, стек PHP + Apache + SQLite зафиксирован.
- **v0.1 (07.05.2026)** — первичный драфт.

## Технологический стек (зафиксировано)
- **OS:** Ubuntu 20.04 (focal)
- **PHP:** 7.4 (модуль Apache `mod_php` или PHP-FPM — оба варианта поддерживаются)
- **Web-server:** Apache 2.4.41 (управление через ISPmanager)
- **БД:** SQLite (отдельный файл `data/bot.db`)
- **Composer:** 2.x (любая актуальная версия, поддерживающая PHP 7.4)
- **Зависимости (PHP-7.4-совместимые версии):** Slim 4 (`slim/slim ^4.12`, `slim/psr7 ^1.6`), Slim Twig View `^3.3`, Twig `^3.4 <3.10` (3.10+ требует PHP ≥ 8.0), Monolog `^2.9` (3.x требует ≥ 8.1), OTPHP `^10.0` (11.x требует ≥ 8.1), GuzzleHttp `^7.4`, vlucas/phpdotenv `^5.4`, ramsey/uuid `^4.2`, PHPUnit `^9.6` (для тестов).
- **Расширения PHP:** `pdo`, `pdo_sqlite`, `mbstring`, `curl`, `json`, `openssl`, `intl` (для Twig), `tokenizer`, `xml` (для phpunit).
- **Установка зависимостей:** через `composer install --no-dev --optimize-autoloader` на VPS (vendor НЕ включается в архив).

> **Примечание о выборе PHP 7.4.** PHP 7.4 находится в end-of-life с ноября 2022 года. Решение использовать его принято осознанно из-за невозможности безболезненно поднять PHP 8.x на действующем VPS (репозиторий `ondrej/php` удалил все пакеты PHP 8.x для focal, sury.org блокирует запросы кодом 418). Доступ к веб-интерфейсу защищён 2FA и не публикуется наружу — риск принят. После очередного обновления VPS целевую версию следует поднять до 8.x.

---

## 1. Цель и область применения

Автоматизированный бот для торговли бессрочными фьючерсами USDT-perpetual на бирже **Bybit**, использующий внешний поток сигналов (`finManager/shared/signalsHourly.json`) и работающий по таймфрейму **1h**.

**Ключевые свойства системы:**
- PHP + Cron на VPS, без долгоживущих демонов и WebSocket-подключений.
- SQLite в виде одного файла как единственное хранилище данных.
- Веб-интерфейс с парольной авторизацией и 2FA для управления, мониторинга и ручного вмешательства.
- **Четыре режима работы** (см. §13a): `paper` (симулятор без отправки на биржу), `testnet` (Bybit Testnet), `live` (Bybit Mainnet), `pause` (бот не открывает новых позиций, есть остаются до закрытия). По умолчанию — `paper`.
- Однопользовательский режим: один экземпляр на один VPS, один набор API-ключей Bybit. Перенос на другой VPS с обнулённой БД делает проект готовым для другого пользователя.
- **Модульная система стратегий:** ядро универсально, каждая стратегия — подключаемый модуль с известным контрактом. На старте реализована Strategy 1; Strategy 2 и 3 описаны в §18 для последующего внедрения без переделки ядра.

---

## 2. Высокоуровневая архитектура

### 2.1. Компоненты

```
bybit-bot/
├── bin/                       # CLI-точки входа
│   ├── cron_hourly.php        # часовой скрипт: автостратегии (S1) — поиск сигнала и постановка conditional
│   ├── cron_minute.php        # минутный скрипт: сопровождение, очередь команд UI, S3-cleanup
│   ├── cron_daily.php         # ежедневная задача: обновление якорного депозита, ротация логов
│   └── cli.php                # утилиты: миграции, сброс, ручной запуск, дамп, auth:init
├── src/
│   ├── Core/                  # ядро: конфиг, логирование, БД, локи, helpers (Rounding и т.д.)
│   ├── Exchange/              # ExchangeAdapter и реализации (Bybit, Paper)
│   ├── Signals/               # источник signalsHourly.json: копирование, парсинг, приоритизация
│   ├── Strategies/            # реестр стратегий
│   │   ├── StrategyRegistry.php
│   │   ├── Strategy1/         # автоматическая (signalsHourly)
│   │   ├── Strategy2/         # ручная — entry/TP/SL абсолютные цены
│   │   └── Strategy3/         # ручная — entry + расстояние в %
│   ├── Trade/                 # машина состояний сделки, репозитории, lifecycle
│   ├── Guards/                # защиты (дневной стоп, balance, funding, sanity); понимают режим warn/block
│   ├── Web/                   # контроллеры веб-интерфейса (Slim 4 + Twig)
│   └── Notify/                # алерты (Telegram)
├── public/                    # веб-корень (index.php) — DocumentRoot Apache
├── data/
│   ├── bot.db                 # SQLite — основная БД
│   ├── signals/               # снапшоты скопированного signalsHourly.json (история)
│   └── secrets/               # API-ключи Bybit, TOTP-secret, пароли (chmod 600)
├── config/
│   ├── settings.php           # bootstrap-настройки (БД-настройки приоритетнее)
│   ├── strategies.php         # список стратегий: enabled / disabled
│   └── instruments_blacklist.php  # стейблкоины и явно исключённые тикеры
├── logs/
└── docs/spec.md
```

### 2.2. Скрипты и их роли

| Скрипт | Триггер | Что делает |
|---|---|---|
| `cron_hourly.php` | Cron каждые `HH:00` (или сдвиг — см. §3.1) | Ищет сигнал, ставит conditional order, обновляет лимиты |
| `cron_minute.php` | Cron каждую минуту | Синхронизация состояния, обработка очереди UI-команд, переходы в state machine, защиты |
| `cron_daily.php` | Cron в 00:00 UTC | Снимок депозита (`deposit_snapshots`), архивация логов |
| Веб-UI | По запросу пользователя | Те же действия + вид на состояние |

Все скрипты:
- Защищены `flock` от повторного входа.
- Имеют гвард на повторный запуск в **тот же час+дату** (для часового) или **ту же минуту** (для минутного).
- Логируют каждое действие в `events`-таблицу с уровнем (`INFO`/`WARN`/`ERROR`/`CRITICAL`).
- Принимают `--mode=paper|testnet|live` или читают режим из `settings.php`.

### 2.3. Режимы работы

| Режим | API-вызовы | Источник цен | Источник исполнений |
|---|---|---|---|
| `paper` | Только GET (баланс, klines, instruments, funding) — read-only | Bybit Mainnet | Симулятор: исполнение определяется сравнением high/low minute-свечей с ценами ордеров |
| `testnet` | Полные read+write | Bybit Testnet | Реальное исполнение testnet |
| `live` | Полные read+write | Bybit Mainnet | Реальное исполнение mainnet |
| `pause` | Только сопровождение существующих позиций | Последний активный режим | Новые conditional не ставятся; уже открытые позиции доживают до закрытия |

**Журнал сделок раздельный по режимам.** В UI выбран режим X — видны только сделки этого режима.

**Переключение в `live` требует повторного ввода пароля** в UI и пишет в `events` запись уровня `CRITICAL`.

---

## 3. Жизненный цикл сделки

### 3.1. Часовой скрипт (`cron_hourly.php`)

Запускается в `HH:01:00` (через 1 минуту после генерации `finManager/shared/signalsHourly.json`, у которого `savedAt = HH:00:08`).

Часовой скрипт обходит **все автоматические стратегии** (`isAutomatic() == true`). На v0.2 это только Strategy 1.

**Шаги:**
1. **Anti-rerun:** проверить в `cron_runs`, что нет успешного запуска с `kind='hourly'` и `slot=YYYY-MM-DD HH`. Если есть — выйти.
2. **Импорт сигналов** (только для S1):
   - читаем первоисточник `/var/www/stascher.ru/finManager/shared/signalsHourly.json`,
   - сохраняем копию в `data/signals/YYYY-MM-DD_HH.json`,
   - формируем обёртку `{ source_saved_at, local_updated_at, signals: [...] }`, где `source_saved_at` берётся из `signals[0].savedAt`, `local_updated_at = now()`.
3. Для каждой включённой автостратегии — вызвать `Strategy::collectAutoIntents()`.
4. **Фильтрация сигналов** (для S1) применяется внутри `collectAutoIntents`:
   - **Свежесть:** `signal.date == today_msk` И `HOUR(signal.time) == current_hour_msk`. Иначе — журнал `signal_stale_skip`, пропуск. Никакого `source_saved_at`, никаких `minutes` threshold для S1.
   - `date == сегодня` (MSK), `time` — текущий час MSK,
   - `potential == false`,
   - `symbol` есть в кэше доступных Bybit USDT-perpetual,
   - `symbol` не в blacklist (стейблкоины и явно исключённые),
   - `|target| ≥ 2.0` (нижний порог),
   - sanity-cap, funding-фильтр (по настройкам стратегии или глобальным),
   - по тикеру нет открытой позиции (иначе сигнал игнорируется).
5. **Приоритизация внутри стратегии** (см. §4.1) — стратегия возвращает 0..N намерений (для S1 — максимум 1).
6. **Глобальные защиты и лимиты** (см. §4.2 и §9). В автоматических стратегиях защиты — **блокирующие** (без подтверждения пользователя).
7. **Замена conditional по тому же тикеру:** если у этой же стратегии уже висит conditional на этот символ → отменить старый.
8. **Расчёт цены входа, TP, SL, плеча, лота** через `Strategy::buildConditionalOrder()` (для S1 — формулы §5).
9. **Размещение conditional** через адаптер биржи. `orderLinkId = bot_<mode>_<strategy_id>_<trade_id>_<unix_ts>`.
10. **Запись:** новая строка в `trades` со статусом `PENDING_CONDITIONAL`, `strategy_id = 's1'` + событие в `trade_events`.
11. **Запись `cron_runs`** (успех).

### 3.2. Минутный скрипт (`cron_minute.php`)

**Шаги:**
1. **Lock + anti-rerun** на минуту.
2. **Команды из UI** (`commands_queue`): обработать всё в статусе `PENDING` (закрыть, отменить, изменить SL/TS/усреднение, ручной conditional из S2/S3, подтверждение защит и т.д.). Команда становится `DONE` или `FAILED`.
3. **Синхронизация с биржей** (источник правды — биржа):
   - `GET /v5/order/realtime` — открытые ордера,
   - `GET /v5/position/list` — открытые позиции,
   - `GET /v5/execution/list` — последние сделки (для определения реальной цены входа),
   - кросс-сверка с локальной БД, fix несоответствий.
4. **S3-cleanup**: для каждой исполненной позиции стратегии S3 — отменить все остальные `PENDING_CONDITIONAL` той же стратегии S3 на тот же символ.
5. **Переходы по state machine** (см. §3.3) для каждой активной сделки. После открытия вызывается `Strategy::onPositionOpened()` соответствующей стратегии (S2 ставит дополнительный 60%-TP reduce-only; S3 наследует поведение S1).
6. **Защиты** (см. §9): дневной стоп, balance long/short, funding. Защиты ловятся и в фазе сопровождения (например, если `daily_drawdown` сработал — блокировать новые открытия не нужно для уже открытых, но фиксируется событие).
7. **Закрытие "просроченных" conditional**: если pending-conditional висит, и **рыночная цена прошла его расчётный SL** (см. §5.5), — отменить ордер.
8. **Funding-пересчёт `P_BE`** для сделок в статусе `AVERAGED` после каждого нового списания (см. §7.4).

### 3.3. State machine сделки

```
                  +----------------------+
   часовой -----> | PENDING_CONDITIONAL  |
                  +----------------------+
                       |          |
                   [исполнен]    [отменён вручную/по SL/новый сигнал]
                       v          v
                +-----------+   +-----------+
                |   OPEN    |   | CANCELLED |
                +-----------+   +-----------+
                  |     |
            [TS сработал, прибыль]   [усреднение исполнено]
                  |                    |
                  v                    v
          +---------------+    +-----------+
          | CLOSED_PROFIT |    | AVERAGED  |
          +---------------+    +-----------+
                                  |     |
                          [TS прибыль]  [SL — убыток ≤ 8% депо]
                                  v     v
                        +---------------+   +--------------+
                        | CLOSED_PROFIT |   | CLOSED_LOSS  |
                        +---------------+   +--------------+
```

Дополнительные флаги:
- `manual_override` (bool) — после ручного действия в UI; снимается кнопкой "вернуть в авто". При возврате в авто бот **не пересчитывает** текущие параметры до следующего триггерного события.
- `mode` (paper/testnet/live) — фиксируется при создании.
- `strategy_id` — фиксируется при создании (`s1`/`s2`/`s3`).

При входе в `OPEN`: **снять условный TP, поставить trailing stop, поставить ордер усреднения, пересчитать SL по реальной цене**. Конкретные действия делегируются `Strategy::onPositionOpened()`:
- **S1, S3** — стандартный набор §6;
- **S2** — то же, плюс **дополнительный reduce-only limit-ордер на 60% объёма по введённому TP**, если `|TP-entry| ≠ |SL-entry|`. Все дальнейшие пересчёты ведутся от `p_SL` (см. §18.2).

При входе в `CLOSED_PROFIT` или `CLOSED_LOSS`: **снять висящий ордер усреднения** (если есть). Для S2 — также снять оставшийся reduce-only ордер на 60%, если он не успел исполниться.
При входе в `AVERAGED`: **пересчитать `P_BE`, перенастроить TS, сдвинуть SL под лимит 8% депо** (см. §7).

**S2: частичный TP на 60% сработал** — это не отдельное состояние, остаёмся в `OPEN`. Текущий объём позиции уменьшился; `qty_current` обновляется в БД. Все последующие пересчёты SL, trailing, усреднения используют `qty_current` (см. §18.2.5).

**S3: исполнение одного из conditional** — все остальные **S3-conditional** по этому же символу автоматически отменяются (выполняется в `cron_minute`, шаг 4).

---

## 4. Сигналы: фильтрация и приоритизация

### 4.1. Алгоритм выбора одного сигнала

Из отфильтрованного списка кандидатов:

1. Если включён режим `обязательно_только_совпадение_w7`:
   - кандидаты на `long`: `weights.w7 > 0`,
   - кандидаты на `short`: `weights.w7 < 0`,
   - если ничего не осталось — выходим без сигнала.

2. Если включён `приоритет_по_w7` (это режим по умолчанию):
   - сначала ищем среди кандидатов с "правильным" знаком `w7` максимальный по `|target|`;
   - если такой группы нет — берём максимальный по `|target|` из всех кандидатов.

3. Если выбран режим `отключить_w7`:
   - просто максимум по `|target|`.

В случае равенства `|target|`: тай-брейкер — больший `|w7|` в правильную сторону, потом — меньший `rsi` для long и больший `rsi` для short, потом — алфавитный порядок `symbol`.

### 4.2. Лимиты на постановку

Лимиты считаются **на уровне аккаунта** (включая позиции/ордера, поставленные не ботом):

- `max_open_positions` (по умолчанию 15),
- `max_total_orders_with_pending` (по умолчанию 20).

Правила:
- если открытых позиций ≥ `max_open_positions` → не ставим;
- если `(открытые + conditional) ≥ max_total_orders_with_pending`:
  - если есть **наши** pending-conditional → отменяем самый старый и ставим новый;
  - если наших нет — пропускаем сигнал;
- если по этому тикеру уже есть **наш** pending-conditional — отменяем его и ставим новый;
- если по этому тикеру есть открытая позиция — пропускаем сигнал.

---

## 4.3. Стейблкоины и blacklist

Стейблкоины определяются хардкод-списком + расширяемым через UI.

**Дефолтный список** (ключ `stablecoins_blacklist` в `settings`, JSON-массив):
```
USDT, USDC, DAI, FDUSD, BUSD, TUSD, USDD, PYUSD, EURC, EURT, USDP, GUSD, FRAX, LUSD, sUSD
```

- Сравнивается по `base_coin` инструмента Bybit.
- Пользователь может редактировать список в UI (добавить/удалить монеты).
- Хранение: `settings(key='stablecoins_blacklist', value=JSON-массив)`.

---

## 5. Формулы открытия

### 5.1. Дельта от цены свечей

```
amplitude_pct = (max(high_n) − min(low_n)) / max(high_n) × 100
delta_pct    = delta_pct_of_amplitude × amplitude_pct
```

где
- `n` — число свечей (по умолчанию `24`, настройка `delta_lookback_candles`),
- `delta_pct_of_amplitude` — доля амплитуды (по умолчанию **0.08**, т.е. 8%).
- Значение 0.08 подтверждено (Уточнения-1). Хранится в `settings`, редактируется через UI.

### 5.2. Цена входа условного ордера

`H1` — high текущей часовой свечи, `L1` — её low.
`H2` — high предыдущей часовой свечи, `L2` — её low.

- Long: `entry_ref = max(H1, H2) × (1 + delta_pct/100)`
- Short: `entry_ref = min(L1, L2) × (1 − delta_pct/100)`

Округление до `tickSize` инструмента: long — **вверх**, short — **вниз** (отдаляемся от рынка, не приближаемся).

### 5.3. Тейк-профит и стоп-лосс условного ордера (предварительные)

`p = |target|` — процент сигнала (модуль).

- Long:
  - `TP_init = entry_ref × (1 + p/100)` — округлить **вниз** до tickSize (не дальше расчётного).
  - `SL_init = entry_ref × (1 − p/100)` — округлить **вверх** до tickSize (не дальше расчётного).
- Short — симметрично (round directions инвертированы).

Реальный установленный `p_TP_used` пересчитываем из округлённой `TP_init` и используем дальше в формуле размера лота.

### 5.4. Плечо и размер лота

**Принцип (v0.7.0):** плечо влияет **только на размер занимаемой маржи**, но **не уменьшает qty**. Размер позиции подбирается так, чтобы при срабатывании `SL_init` потеря составляла ровно `base_lot_usdt` (т.е. 1% от deposit_anchor). Плечо отвечает за «капитальную эффективность» — даёт открыть позицию большего номинала на ту же маржу — но не за управление риском.

```
leverage = min(max_leverage_recommended_by_bybit(symbol), max_leverage_cap_from_settings)
base_lot_usdt    = 0.01 × deposit_anchor                      # 1% от "якорного" депозита, см. §8
notional_usdt    = base_lot_usdt × 100 / p_TP_used            # «unleveraged_usdt», полный номинал позиции в USDT
order_qty_raw    = notional_usdt / entry_ref                  # qty монет ДО safety и округления; ПЛЕЧО НЕ УЧАСТВУЕТ
order_qty_safe   = order_qty_raw × (1 − qty_safety_margin_pct/100)   # см. ниже
order_qty_coins  = floor_to_step(order_qty_safe, qtyStep)
order_margin_usdt = (order_qty_coins × entry_ref) / leverage         # сколько маржи реально займёт позиция
```

Округление `order_qty_coins` к `qtyStep` инструмента — **вверх** до минимума, **вниз** в остальных случаях.

**Safety margin (v0.7.0):** настройка `qty_safety_margin_pct` (по умолчанию `10.0`) — защитный зазор от расчётного qty. Bybit при размещении ордера проверяет доступную маржу с учётом проскальзываний/funding/комиссий, и фактически принимает qty чуть меньше расчётного. Зазор задаётся в процентах, диапазон `0..50`. `0` — отключить (использовать чистый расчёт).

**Минимальный лот биржи:**
- Если `qty_min × entry_ref > notional_usdt`, действует настройка `min_lot_overshoot_pct`:
  - `0` (по умолчанию) — входим по `qty_min` (риск > 1%);
  - `>0` — входим, только если `qty_min × entry_ref ≤ notional_usdt × (1 + min_lot_overshoot_pct/100)`;
  - в противном случае — пропуск сигнала (`SKIPPED_TOO_SMALL`).

`ASSUMPTION:` тот же механизм применяется и в обратной ситуации, когда `order_qty_coins < qty_min` (символ слишком "крупный" для нашего лота).

**Изменение vs v0.6.x (важно):** ранее в формуле фигурировало `order_qty_usdt = unleveraged_usdt / leverage` — что давало позиции в `leverage` раз меньше и фактически масштабировало риск на 1/leverage. С v0.7.0 это поведение исправлено: риск на сделку = `base_lot_usdt`, как и заявлено в §5.4. См. также §5.6.

### 5.5. SL pending-conditional для проверки в минутном скрипте

Если pending-conditional ещё не исполнен, но текущая цена биржи прошла его расчётный `SL_init` в "плохую" сторону (для long — упала ниже `SL_init`, для short — выросла выше `SL_init`) — отменяем ордер. Это правило применяется **только** к нашим conditional'ам.

### 5.6. Числовой пример (v0.7.0)

**Пример A — SPXUSDT, paper, real-world (по скриншоту Bybit):**

- Депозит: **300 USDT**, сигнал short `p = 8.26%`, `entry_ref = 0.4614`, `leverage = 50x`, `qtyStep = 1`, `qty_safety_margin_pct = 10.0`
- `base_lot_usdt    = 0.01 × 300 = 3.0 USDT`
- `notional_usdt    = 3.0 × 100 / 8.26 = 36.32 USDT`
- `order_qty_raw    = 36.32 / 0.4614 = 78.72 SPX`
- `order_qty_safe   = 78.72 × 0.9 = 70.85 SPX`
- `order_qty_coins  = floor_to_step(70.85, 1) = 70 SPX` (или 71 — зависит от точного safety)
- `order_margin_usdt = (70 × 0.4614) / 50 = 0.646 USDT`
- Потеря при срабатывании SL_init = 70 × |0.4995 − 0.4614| = **2.67 USDT** ≈ 0.89% от депозита (с safety 10% — недобор по риску на 11%; без safety было бы 78 × 0.0381 = 2.97 ≈ 0.99% ≈ ровно 1%).

**Пример B — старый сигнал из Уточнений-11, пересчитанный по новой формуле:**

- Депозит: **357.1384 USDT**, short `p = 2.25%`, `entry_ref = 0.006789`, `leverage = 50`, `qty_safety_margin_pct = 10.0`
- `base_lot_usdt    = 3.5714 USDT`
- `notional_usdt    = 3.5714 × 100 / 2.25 = 158.7282 USDT`
- `order_qty_raw    = 158.7282 / 0.006789 ≈ 23 380 монет`
- `order_qty_safe   = 23 380 × 0.9 ≈ 21 042 монет`
- `order_qty_coins  = floor_to_step(21 042, qtyStep)`
- `order_margin_usdt = (21 042 × 0.006789) / 50 ≈ 2.86 USDT`

> Старая формула (с `/ leverage` для qty) давала 467 монет — в 50 раз меньше, чем нужно для риска 1%. Удалено в v0.7.0.

---

## 6. Управление активной позицией (после исполнения)

Минутный скрипт детектирует переход conditional → позиция и выполняет:

### 6.1. Реальная цена входа

`P_real` = средневзвешенная цена из последних `execution.list` для данного `orderLinkId` (учёт частичных исполнений).

### 6.2. Пересчёт SL

```
sign      = +1 для long, -1 для short
SL_real   = P_real − (P_real × sign × p × 2 × market_coef) / 100
```

`market_coef` — настройка (по умолчанию `1.35`).

### 6.3. Замена TP на trailing stop

```
movement_coef = floor(p / 4) + 1            # шкала «по четвёркам»: p∈[0,4)→1, p∈[4,8)→2, p∈[8,16)→3, p∈[16,32)→4 ...
                                            # ВНИМАНИЕ: ранее в спецификации стояло 2^k≥p — это была ошибка.
trailing_pct  = floor_to_tenth( p / movement_coef − 0.3 )
trigger_price = P_real + (P_real × sign × (p / movement_coef)) / 100
```

**Пример:** при `p = 8.26%`, short, `P_real = 0.4614`:
- `movement_coef = floor(8.26/4) + 1 = 3`
- `trailing_pct  = floor_to_tenth(8.26/3 − 0.3) = floor_to_tenth(2.453) = 2.4`
- `trigger_price = 0.4614 + (0.4614 × (−1) × 2.753) / 100 = 0.4487`

В Bybit API: `setTradingStop(trailingStop=trailing_pct, activePrice=trigger_price)`.

### 6.4. Постановка ордера усреднения

```
avg_price = P_real − (P_real × sign × p × 1.5 × market_coef) / 100
avg_qty   = current_qty × 2 × market_coef  # current_qty на момент постановки усреднения (Уточнения-6)
```

Округления: `avg_price` к tickSize так же, как для входа; `avg_qty` к qtyStep вверх.

### 6.5. Триггеры пересчёта в фазе `OPEN`

| Событие | Действие |
|---|---|
| TS сработал и позиция закрыта в плюс | `CLOSED_PROFIT`, отменить avg-ордер |
| SL сработал | `CLOSED_LOSS`, отменить avg-ордер |
| avg-ордер исполнен | переход в `AVERAGED`, пересчёт §7 |
| `manual_override = true` | ничего не делаем автоматически до возврата в авто |

---

## 7. Управление после усреднения (`AVERAGED`)

### 7.1. Точка безубытка

```
fees_open  = (Q1 × P1 × taker_fee) + (Q2 × P2 × taker_fee)
fees_close = (Q1 + Q2) × P_BE_provisional × taker_fee   # provisional ≈ avg(P1,P2)
funding    = ∑ funding_payments (сохраняются в trade_funding_log)

P_BE = (P1×Q1 + P2×Q2 + sign×(fees_open + fees_close + funding)) / (Q1 + Q2)
```

`taker_fee` — настройка (по умолчанию 0.055% для perpetual; уточняется по тарифам пользователя).
`ASSUMPTION:` используем taker для всех ног, потому что conditional исполняется как market.

### 7.2. Trailing после усреднения

```
trailing_pct  = 1.0
trigger_price = P_BE + sign × P_BE × 2 / 100
```

### 7.3. SL после усреднения (защита 8% депо)

```
max_loss_usdt = 0.08 × deposit_anchor
SL_distance   = max_loss_usdt / (Q1 + Q2)
SL_post_avg   = P_BE − sign × SL_distance
```

`ASSUMPTION:` SL не должен быть ближе к рынку, чем уже стоящий — двигаем только в сторону "лучше". Если расчётный `SL_post_avg` хуже текущего, оставляем текущий.

### 7.4. Funding-пересчёт `P_BE`

При появлении новой записи в `trade_funding_log`:
- если `funding > 0` (мы платим) — пересчитываем `P_BE`, сдвигаем TS и SL соответственно;
- если `funding < 0` (платят нам) — пересчитываем `P_BE` (улучшается), но **SL/TS не двигаем "хуже"** в сторону рынка, только "лучше". Trigger price TS можно подтягивать.

---

## 8. Депозит для расчёта 1%

`deposit_anchor` — фиксированная величина, используемая в формулах §5.4 и §7.3.

- Хранится в `deposit_snapshots(date, value, source)`.
- **Автообновление раз в сутки** в `cron_daily.php`: берёт `totalWalletBalance` из `/v5/account/wallet-balance` (UTA, `accountType=UNIFIED`) и сохраняет как `deposit_anchor` на новый день. **Не** `totalEquity` (Уточнения-3).
- Кнопка в UI "обновить депозит сейчас" — внеплановое обновление.
- Для `paper`-режима стартовое значение задаётся настройкой `paper_initial_deposit_usdt` (по умолчанию **300** USDT, Уточнения-2).

---

## 9. Защиты (`Guards`)

Все защиты — настраиваемые, по умолчанию **отключены** (кроме нижнего порога 2% сигнала, он включён всегда).

| ID | Что делает | Настройки | Дефолт |
|---|---|---|---|
| `daily_drawdown` | Блок новых ордеров до завтра, если депозит просел на X% | `enabled`, `pct` | off, 10% |
| `long_short_balance` | Не открывать в направлении, превысившем долю; **проверка только если `total_orders ≥ 6`** | `enabled`, `max_share_pct`, `min_total_to_check` | off, 70%, 6 |
| `funding_filter` | Не открывать против экстремального funding | `enabled`, `extreme_funding_pct_per_8h` | off, 0.1% |
| `signal_upper_cap` | Игнорировать сигналы с `|target|` выше | `enabled`, `max_target_pct` | off, 10% |
| `min_lot_overshoot` | Допуск превышения 1% риска при минимальном лоте биржи | `enabled`, `pct` | off, 0% |
| `leverage_cap` | Верхний предел плеча, если рекомендация Bybit выше | `enabled`, `value` | off, 50 |
| `max_open_positions` | Лимит открытых позиций на аккаунт | — (см. §13) | 15 |
| `max_total_orders_with_pending` | Лимит позиций+conditional на аккаунт | — (см. §13) | 20 |

### 9.1. Режимы срабатывания: `block` vs `warn`

Каждая защита имеет режим работы, зависящий от стратегии, инициировавшей открытие:
- **Автоматические стратегии (S1)**: все защиты — `block`. Сигнал просто пропускается, событие в журнал.
- **Ручные стратегии (S2/S3)**: все защиты — `warn`. При попытке поставить ордер из UI открывается **модальное окно "вы уверены?"** с описанием срабатывающих защит. Пользователь может подтвердить и ордер всё равно ставится. `daily_drawdown` помечается как **критическая** в модалке (выделено цветом и текстом), но финальное решение — за пользователем.

### 9.2. Двухуровневая система настроек

Все настройки защит могут быть **переопределены на уровне стратегии**. В UI каждой стратегии есть страница "Настройки", где для каждого ключа можно выбрать:
- "Использовать глобальное" (по умолчанию) — берётся значение из глобальных настроек,
- "Переопределить" — вводится своё значение, которое работает только для сделок этой стратегии.

Хранение: глобальные значения — в таблице `settings`, переопределения — в `strategy_settings(strategy_id, key, value)`.

---

## 10. Округление к tickSize / qtyStep

Единый хелпер `Rounding::roundToStep(float $value, float $step, string $direction)` где `direction ∈ {up, down, nearest}`.

Правила направлений:

| Что округляем | Сторона | Направление |
|---|---|---|
| `entry_ref` (long) | — | up |
| `entry_ref` (short) | — | down |
| `TP` (long/short) | внутрь | down (long), up (short) |
| `SL_init` (long/short) | внутрь | up (long), down (short) |
| `SL_real` | в свою пользу | up (long), down (short) |
| `avg_price` (long/short) | как entry, в "плохую" сторону | down (long), up (short) |
| `trigger_price` TS | как TP | down (long), up (short) |
| `qty` (open/avg) | вверх до minimal, иначе вниз | up если ниже минимума, down иначе |

Все округления — **до отправки** на биржу. Нарушение направления = ошибка реализации.

---

## 11. Margin mode

Настройка `bybit_margin_mode` ∈ {`cross`, `isolated`}, по умолчанию **`cross`** (Уточнения-4).

- Хранится в `settings(key='bybit_margin_mode')`.
- Применяется при размещении первого ордера по символу: перед `placeConditional` вызывается `switchMarginMode(symbol, mode, leverage)`.
- В `PaperAdapter` вызов игнорируется (no-op).
- В реальном адаптере (Stage 3): `POST /v5/position/switch-isolated` с `tradeMode=0` (cross) или `1` (isolated).

---

## 12. Адаптер биржи (`ExchangeAdapter`)

В дополнение к торговым методам адаптер должен поддерживать **частичные reduce-only лимит-ордера** (`placeReduceOnlyLimit`) для механики S2 (60% TP).


```
interface ExchangeAdapter {
  // справочники и состояние
  getInstrumentInfo(symbol)                  -> {tickSize, qtyStep, qtyMin, leverageFilter}
  getKline(symbol, interval, limit)          -> [{open,high,low,close,start,...}]
  getWalletBalance()                          -> {totalEquity, availableBalance, ...}
  getPositions(symbol?=null)                 -> [...]
  getOpenOrders(symbol?=null)                -> [...]
  getExecutions(orderLinkId|symbol, since)   -> [...]
  getFundingRate(symbol)                     -> {rate, nextFundingTime}
  getFundingHistory(symbol, since)           -> [...]
  getServerTime()                            -> int
  // торговые
  placeConditional(params)                   -> orderId
  cancelOrder(orderId|orderLinkId)           -> bool
  amendOrder(orderId, fields)                -> bool
  setTradingStop(symbol, side, fields)       -> bool   // SL/TP/trailing/active price
  setLeverage(symbol, value)                 -> bool
  switchMarginMode(crossOrIsolated)          -> bool
  placeReduceOnlyLimit(symbol, side, qty, price, orderLinkId)  -> orderId
}
```

Реализации: `BybitAdapter` (REST V5, HMAC), `BybitTestnetAdapter` (тот же класс с другой `baseUrl`), `PaperAdapter` (read-only к Bybit Mainnet + локальная симуляция исполнений в таблице `paper_orders`/`paper_positions`).

Технические требования к адаптеру:
- Подпись V5: `timestamp + apiKey + recvWindow + queryString|body` через HMAC-SHA256.
- `recvWindow = 5000ms`, авто-проверка `time drift` со `getServerTime()`; алерт при drift > 2 сек.
- Идемпотентный `orderLinkId`.
- Retry с экспоненциальной задержкой на 429/5xx (max 3 ретрая, jitter).
- Сохранение **сырых ответов** на торговые операции в `api_calls` (для разбора инцидентов).

---

## 12. Схема БД (SQLite)

### 12.1. Таблицы

```sql
-- режим работы фиксируется на каждой сделке
CREATE TABLE trades (
  id                   INTEGER PRIMARY KEY,
  mode                 TEXT NOT NULL,            -- 'paper'|'testnet'|'live'
  strategy_id          TEXT NOT NULL,            -- 's1'|'s2'|'s3'|...
  symbol               TEXT NOT NULL,
  side                 TEXT NOT NULL,            -- 'long'|'short'
  signal_target_pct    REAL,                     -- NULL для S2/S3 (там нет signal.target)
  signal_w7            INTEGER,
  signal_rsi           REAL,
  signal_payload_json  TEXT,                     -- полный сигнал (S1) или manual_input (S2/S3)

  -- ручные стратегии: введённые пользователем
  manual_tp_price      REAL,                     -- S2: абсолютная цена TP
  manual_sl_price      REAL,                     -- S2: абсолютная цена SL
  manual_distance_pct  REAL,                     -- S3: расстояние до SL=TP в %

  -- p_SL для всех расчётов после открытия (для S2 — ключевая величина)
  p_for_strategy_calc  REAL,                     -- p в %, по которому считаются avg/TS

  status               TEXT NOT NULL,            -- enum в §3.3
  manual_override      INTEGER NOT NULL DEFAULT 0,

  -- цены и лот
  entry_ref            REAL,                     -- расчётная цена условного
  entry_real           REAL,                     -- средневзвеш. реал. вход
  qty_initial          REAL,                     -- кол-во монет (изначальное)
  qty_current          REAL,                     -- текущее (для S2 после частичного TP)
  qty_avg              REAL,                     -- кол-во усреднения, если было
  leverage             INTEGER,
  margin_mode          TEXT,                     -- 'cross'|'isolated'

  -- параметры по фазам
  tp_init              REAL,
  sl_init              REAL,
  sl_current           REAL,
  trailing_pct         REAL,
  trailing_trigger     REAL,
  avg_price            REAL,                     -- цена усреднения (заявленная)
  break_even_price     REAL,                     -- P_BE с комиссиями+funding

  -- ID на бирже
  order_link_id_open   TEXT UNIQUE,
  order_link_id_avg    TEXT UNIQUE,
  order_link_id_part_tp TEXT UNIQUE,             -- S2: reduce-only лимитка на 60% по TP
  order_id_open        TEXT,
  order_id_avg         TEXT,
  order_id_part_tp     TEXT,

  -- даты
  created_at           TEXT NOT NULL,
  opened_at            TEXT,
  averaged_at          TEXT,
  closed_at            TEXT,

  -- итоги
  realized_pnl_usdt    REAL,
  fees_total_usdt      REAL,
  funding_total_usdt   REAL
);

CREATE INDEX idx_trades_mode_status     ON trades(mode, status);
CREATE INDEX idx_trades_strategy_status ON trades(strategy_id, status);
CREATE INDEX idx_trades_symbol          ON trades(symbol);
CREATE INDEX idx_trades_created_at      ON trades(created_at);

CREATE TABLE trade_events (
  id          INTEGER PRIMARY KEY,
  trade_id    INTEGER NOT NULL REFERENCES trades(id),
  ts          TEXT NOT NULL,
  level       TEXT NOT NULL,                     -- INFO|WARN|ERROR|CRITICAL
  kind        TEXT NOT NULL,                     -- e.g. 'placed','executed','sl_moved',...
  payload_json TEXT
);

CREATE TABLE trade_funding_log (
  id          INTEGER PRIMARY KEY,
  trade_id    INTEGER NOT NULL,
  ts          TEXT NOT NULL,
  amount_usdt REAL NOT NULL,
  rate        REAL,
  applied     INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE events (
  id     INTEGER PRIMARY KEY,
  ts     TEXT NOT NULL,
  level  TEXT NOT NULL,
  kind   TEXT NOT NULL,                          -- 'cron_run','signal_skipped','api_error',...
  symbol TEXT,
  payload_json TEXT
);

CREATE TABLE cron_runs (
  id     INTEGER PRIMARY KEY,
  kind   TEXT NOT NULL,                          -- 'hourly'|'minute'|'daily'
  slot   TEXT NOT NULL,                          -- '2026-05-07 09' / '2026-05-07 09:31'
  status TEXT NOT NULL,                          -- 'started'|'success'|'failed'
  started_at  TEXT NOT NULL,
  finished_at TEXT,
  message TEXT
);
CREATE UNIQUE INDEX idx_cron_runs_slot ON cron_runs(kind, slot);

CREATE TABLE deposit_snapshots (
  id     INTEGER PRIMARY KEY,
  mode   TEXT NOT NULL,
  ts     TEXT NOT NULL,
  value  REAL NOT NULL,
  source TEXT NOT NULL                           -- 'auto_daily'|'manual'|'paper_initial'
);

CREATE TABLE commands_queue (
  id          INTEGER PRIMARY KEY,
  trade_id    INTEGER,                           -- может быть NULL для глобальных команд
  ts_created  TEXT NOT NULL,
  ts_done     TEXT,
  status      TEXT NOT NULL,                    -- PENDING|DONE|FAILED
  kind        TEXT NOT NULL,                    -- close_now|cancel_avg|move_sl|set_ts|...
  payload_json TEXT,
  result_json  TEXT
);

CREATE TABLE api_calls (
  id          INTEGER PRIMARY KEY,
  ts          TEXT NOT NULL,
  endpoint    TEXT NOT NULL,
  method      TEXT NOT NULL,
  request_json  TEXT,
  response_json TEXT,
  http_status INTEGER,
  duration_ms INTEGER,
  trade_id    INTEGER
);

-- симулятор
CREATE TABLE paper_orders (
  id            INTEGER PRIMARY KEY,
  trade_id      INTEGER NOT NULL,
  kind          TEXT NOT NULL,                  -- conditional_open|avg|trailing|sl
  side          TEXT NOT NULL,
  trigger_price REAL,
  limit_price   REAL,
  qty           REAL,
  status        TEXT NOT NULL,                  -- pending|filled|cancelled
  filled_at     TEXT,
  filled_price  REAL
);

CREATE TABLE settings (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

-- Per-strategy override настроек: при наличии записи перебивает глобальную
CREATE TABLE strategy_settings (
  strategy_id TEXT NOT NULL,
  key         TEXT NOT NULL,
  value       TEXT NOT NULL,
  updated_at  TEXT NOT NULL,
  PRIMARY KEY (strategy_id, key)
);

-- Метаданные включённости стратегий (управляется в UI и/или config/strategies.php)
CREATE TABLE strategies (
  id          TEXT PRIMARY KEY,                  -- 's1','s2','s3'
  name        TEXT NOT NULL,
  enabled     INTEGER NOT NULL DEFAULT 1,
  is_automatic INTEGER NOT NULL DEFAULT 0,
  description TEXT,
  updated_at  TEXT NOT NULL
);

CREATE TABLE auth_users (
  id            INTEGER PRIMARY KEY,
  username      TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,                  -- bcrypt
  totp_secret   TEXT,
  created_at    TEXT NOT NULL,
  last_login_at TEXT
);

CREATE TABLE auth_sessions (
  id           TEXT PRIMARY KEY,                -- session token
  user_id      INTEGER NOT NULL,
  ts_created   TEXT NOT NULL,
  ts_expires   TEXT NOT NULL,
  ip           TEXT,
  user_agent   TEXT
);
```

### 12.1.1. Новые таблицы (миграция 003)

**`signal_id INTEGER REFERENCES signals(id)`** — добавлена в `trades` (связь с источником сигнала).

**`orders`** — state-machine ордеров: `purpose` (`entry_conditional`/`sl`/`tp_partial`/`avg`/`trailing`), `status` (`pending`/`placed`/`filled`/`cancelled`/`rejected`), `paper` (0/1). Индексы: `(trade_id, status)`, `(status, paper)`.

**`positions`** — позиции: `qty` (текущий), `qty_initial` (на открытии), `close_reason` (`tp`/`sl`/`trailing`/`manual`/`avg_then_tp`), `paper` (0/1). UNIQUE `(trade_id)`.

**`paper_positions`** — симулированные позиции с полями `sl_price`, `tp_price`, `trailing_pct`, `trailing_trigger_price` для tick-симулятора.

### 12.1.2. Дополнительные колонки (миграция 004 — Stage 3)

**`orders.exchange TEXT NOT NULL DEFAULT 'paper'`** — биржа ордера: `'paper'`/`'testnet'`/`'live'`. Фиксируется при создании, не изменяется при переключении режима.

**`orders.sl_price REAL`** — цена SL для pre-fill cancel проверки (§5.5). Сохраняется в `orders` при постановке conditional-ордера.

**`positions.exchange TEXT NOT NULL DEFAULT 'paper'`** — аналогично `orders.exchange`.

**`positions.sl_price REAL`**, **`positions.tp_price REAL`**, **`positions.trailing_pct REAL`**, **`positions.trailing_trigger_price REAL`** — перенесены из `paper_positions` в унифицированную схему. `paper_positions` сохраненъ для обратной совместимости (не дропается).

### 12.2. Соглашения

- `ts` — ISO-8601 UTC `YYYY-MM-DDTHH:MM:SS.sssZ`.
- Все денежные значения — `REAL` (USDT) с точностью до 8 знаков.
- В UI отображение в локальном таймзоне Europe/Moscow.
- Миграции — простые `.sql`-файлы, применяемые `cli.php migrate`.

### 12.7 Сигналы и Bybit-кеш (введены в v0.3.0)

**`signals`** — импорт из `signalsHourly.json` (источник `paths.signals_source`).
Источник использует пояс Europe/Moscow (UTC+3) в поле `savedAt`.
Импортируются только сигналы с `saved_at_utc > MAX(saved_at_utc)` (вариант «только новые»).
Служебное хранение — 30 дней (cleanup в `cron_daily`).

| Поле | Тип | Описание |
|---|---|---|
| `date`, `time`, `saved_at` | TEXT | Как в источнике (MSK) |
| `saved_at_utc` | TEXT | Для сортировки/фильтра (ISO-8601 UTC) |
| `symbol` | TEXT | Исходный тикер без USDT (`TON`, `PEPE`) |
| `side` | TEXT | `long` / `short` |
| `target` | REAL | Целевая цена из источника |
| `strategy` | INT | 1/2/3 (из источника) |
| `potential` | INT | 0/1 |
| `rsi`, `w7`, `w14`, `w30`, `w_all` | REAL/INT | Метрики |
| `bybit_symbol` | TEXT | `TONUSDT`, `1000PEPEUSDT` (резолвлено) |
| `resolution_status` | TEXT | `resolved` / `unresolved` |
| `imported_at` | TEXT | UTC ISO-8601 |

Уникальный индекс: `(date, time, symbol, side)`.

**`bybit_instruments`** — кеш `/v5/market/instruments-info?category=linear`. Поля: `symbol`, `base_coin`, `quote_coin`, `contract_type`, `status`, `tick_size`, `qty_step`, `min_order_qty`, `max_leverage`, `raw_json`. Обновляется в `cron_daily`.

**`symbol_aliases`** — ручные маппинги «source symbol → bybit symbol» (приоритет над автопоиском). Добавляются через CLI `symbols:alias` или UI (Этап 3+).

**`bybit_health_pings`** — журнал health-проверок Bybit (`public` / `signed`). Используется в /healthz.

### 12.8 Резолвер тикеров (`MarketInfo::resolve`)

Порядок:
1. `symbol_aliases.source_symbol` → взять `bybit_symbol` (highest priority, ручной override).
2. Среди `bybit_instruments` искать `{symbol}USDT` с `status='Trading'` и `quote_coin='USDT'`.
3. Перебрать префиксы `10`, `100`, `1000`, `10000`: для `PEPE` результат — `1000PEPEUSDT`.
4. Ничего не найдено → `resolution_status='unresolved'`. Пользователь видит в UI и может добавить алиас вручную.

### 12.9 Bybit V5 API клиент (`src/Bybit/`)

- **Подпись:** `HMAC-SHA256(timestamp + apiKey + recvWindow + payload, apiSecret)` в HEX.
  `payload` = query string (GET) или JSON body (POST), как уходит по сети.
- **Заголовки:** `X-BAPI-API-KEY`, `X-BAPI-TIMESTAMP`, `X-BAPI-RECV-WINDOW`, `X-BAPI-SIGN`, `X-BAPI-SIGN-TYPE: 2`.
- **Retry:** до 3 попыток для `transient` (5xx, timeout, retCode 10002/10016) и `rate_limit` (HTTP 429, retCode 10006/10018/10019). Backoff экспоненциальный: 1–2–4 сек (transient), 1–2–5 сек (rate-limit, cap 5с).
- **Не retry:** `auth` (10003/10004/10005/33004), `permanent` (все остальные 4xx + retCode≠0).
- **Ключи:** для `paper`/`testnet` модов — `BYBIT_API_KEY_TESTNET`/`BYBIT_API_SECRET_TESTNET`. Для `live` — отдельные mainnet-ключи.
- **Логирование:** каждый запрос пишется в `api_calls`. Тело запроса/ответа — только при `BYBIT_DEBUG_LOG=true`. Не-200 сохраняются всегда (для расследований).
- **Health-ping:** встроен в `cron_hourly` при `settings.bybit_health_ping_enabled=true`. /healthz проверяет что последний успешный ping ≤2 часа назад.

### 12.10 OTPHP API (фиксируется из-за v0.3.0 исправления)

Используется OTPHP `^10.0` (PHP 7.4-совместимый). API:
- `TOTP::create()` — без аргументов: генерирует новый секрет (`createUser`).
- `TOTP::create($base32Secret)` — загружает существующий секрет (`login`).
- В версиях 11.x были отдельные `TOTP::generate()` и `TOTP::createFromSecret()` — в 10.x этих методов НЕТ, использовать их нельзя.

---

## 13. Настройки (`settings`)

| Ключ | Тип | Дефолт | Описание |
|---|---|---|---|
| `mode` | enum | `paper` | `paper`/`testnet`/`live` |
| `delta_pct_of_amplitude` | float | `0.08` | Доля амплитуды для δ |
| `delta_lookback_candles` | int | `24` | Сколько часовых свечей для амплитуды |
| `market_coef` | float | `1.35` | "Коэффициент рынка" для SL/avg |
| `taker_fee_pct` | float | `0.055` | Комиссия taker (на ногу) |
| `max_open_positions` | int | `15` | Лимит открытых позиций (на аккаунт) |
| `max_total_orders_with_pending` | int | `20` | Лимит совокупный |
| `leverage_cap` | int | `50` | Верхний предел плеча |
| `qty_safety_margin_pct` | float | `10.0` | Защитный зазор от расчётного qty (v0.7.0). Диапазон 0..50. `0` — отключить. См. §5.4. |
| `margin_mode` | enum | `cross` | `cross`/`isolated` |
| `min_signal_target_pct` | float | `2.0` | Нижний порог сигнала |
| `signal_upper_cap.enabled` | bool | `false` | Sanity-cap на верх |
| `signal_upper_cap.pct` | float | `10` | Верхний порог |
| `long_short_balance.min_total_to_check` | int | `6` | Проверять баланс long/short только если суммарно ордеров ≥ N |
| `daily_drawdown.enabled` | bool | `false` | Глобальный дневной стоп |
| `daily_drawdown.pct` | float | `10` | Порог просадки |
| `long_short_balance.enabled` | bool | `false` | Баланс направлений |
| `long_short_balance.max_share_pct` | float | `70` | Макс доля одной стороны |
| `funding_filter.enabled` | bool | `false` | Фильтр по funding |
| `funding_filter.extreme_pct` | float | `0.1` | Порог |
| `min_lot_overshoot.pct` | float | `0` | Допуск превышения 1% |
| `weights_mode` | enum | `priority` | `off`/`priority`/`mandatory` |
| `paper_initial_deposit_usdt` | float | `300` | Старт paper |
| `deposit_anchor_ttl_hours` | int | `24` | Каждые сколько часов автообновлять |
| `signals_freshness_minutes` | int | `90` | Допустимая старость JSON источника. Выше порога — WARN в events |
| `bybit_health_ping_enabled` | bool | `false` | Включать Bybit-пинг в cron_hourly + проверку в /healthz |
| `ui.alert_loss_pct` | float | `3` | Подсветка строки красным при нереал. убытке |
| `ui.deposit_color_thresholds` | json | см. §14 | Пороги цветовой подсветки |
| `telegram.enabled` | bool | `false` |  |
| `telegram.bot_token`, `telegram.chat_id` | str | — | хранятся в `data/secrets/` |

---

## 13a. Режимы работы (подробно)

### 13a.1. Определение режимов

Система поддерживает четыре режима, управляемые через `settings(key='mode')`:

| Режим | Описание |
|---|---|
| `paper` | Симуляция: ордера исполняются локальным тик-симулятором (`PaperAdapter`). Никаких реальных ордеров на бирже. |
| `testnet` | Реальные ордера на Bybit Testnet. Используются ключи `BYBIT_API_KEY_TESTNET` / `BYBIT_API_SECRET_TESTNET`. |
| `live` | Реальные ордера на Bybit Mainnet. Используются ключи `BYBIT_API_KEY_MAINNET` / `BYBIT_API_SECRET_MAINNET`. Требует повторного ввода пароля. |
| `pause` | Бот не открывает новых позиций (не запускает `collectAutoIntents`). Уже открытые позиции доживают до естественного закрытия. |

### 13a.2. Ключевые правила

1. **Каждый ордер и позиция хранят свой `exchange`** — переключение режима не меняет `exchange` уже открытых ордеров/позиций. Ордер, поставленный в `paper`-режиме, остаётся `paper`-ордером даже после переключения в `testnet`.
2. **`pause` не влияет на `cron_minute`** — минутный скрипт продолжает сопровождать все активные ордера и позиции независимо от режима. `pause` блокирует только `cron_hourly` (сбор намерений).
3. **`AdapterFactory::forCurrentMode()`** — фабрика читает `Config::get('mode')` и возвращает: `paper` → `PaperAdapter`, `testnet` → `BybitAdapter('testnet')`, `live` → `BybitAdapter('live')`, `pause` → адаптер по последнему активному режиму (read-only).
4. **Pre-fill cancel**: если `pending_conditional` ещё не исполнен, но рыночная цена прошла `sl_price` в плохую сторону — отменить ордер: long — `market_low ≤ sl_price`, short — `market_high ≥ sl_price`. `sl_price` хранится в `orders.sl_price` при постановке.

### 13a.3. CLI-команды (Stage 3)

| Команда | Описание |
|---|---|
| `bybit:mode get` | Показать текущий режим |
| `bybit:mode set <mode> [--confirm]` | Переключить режим. Без `--confirm` выводит подсказку |
| `paper:close-all` | Отменить все активные paper-ордера и позиции |
| `reconcile:now [exchange]` | Запустить сверку (testnet/live); без аргумента — текущий не-paper режим |
| `exchange:status` | Таблица: открытые ордера, позиции, общий PnL по каждой бирже |

---

## 14. Веб-интерфейс

### 14.1. Авторизация

- HTTPS обязателен (рекомендуется Caddy/Nginx + Let's Encrypt).
- Логин/пароль (bcrypt) + TOTP 2FA.
- Пароль и TOTP-secret хранятся в `data/secrets/` (chmod 600), могут быть инициализированы через `cli.php auth:init`.
- Сессионная cookie `HttpOnly; Secure; SameSite=Strict`.
- TTL сессии — 8 часов, при бездействии 1 час.
- Защита от brute-force: 5 попыток / 15 мин на IP.

### 14.2. Страницы

1. **Дашборд** — депозит с цветовой подсветкой (см. ниже), число открытых позиций/conditional, P&L день/неделя/месяц, кнопки переключения режима (paper/testnet/live/pause с подтверждением для live).
2. **Журнал сделок** (`/trades`) — таблица в режиме текущего выбранного режима. Фильтры: по статусу, символу, стратегии, режиму. Авто-обновление каждые 60 с. Колонки: `Дата выставления`, `Время выставления`, `Направление`, `Плечо`, `Тикер`, `Цена открытия`, `Объём (монет)`, `Объём (USDT)`, `% до цели (изначальный)`, `Дата закрытия`, `Время закрытия`, `P/L`, `Manual?`.
3. **Журнал событий** — фильтр по уровню, по времени, по символу.
4. **Сводная статистика** — таблица по неделям и месяцам (см. §14.5).
5. **Настройки** (`/settings`) — все ключи из §13 с валидацией и подсказками. Группы: режим работы, лимиты, защиты, стратегии, стейблкоины. Action-кнопки: обновить депозит, обновить инструменты. POST /settings — сохранение всех настроек в таблицу `settings`.
6. **Сделка (детально)** (`/trades/{id}`) — карточка с историей `trade_events`, `orders`, `positions`, кнопки действий (см. §14.3).

### 14.3. Действия по сделке (кнопки в карточке)

- `Закрыть сейчас` (market close)
- `Отменить ордер усреднения`
- `Изменить ордер усреднения` (цена и/или объём)
- `Передвинуть SL` (на абсолютную цену или процент)
- `Изменить trailing-stop` (коррекция/триггер)
- `Установить фиксированный TP`
- `Включить/выключить manual_override`
- `Вернуть в авто` (снимает manual_override; бот не пересчитывает текущие параметры до следующего триггера)

Действия — гибридные: UI пишет в `commands_queue` и **сразу** вызывает обработчик команд (тот же код, что и в `cron_minute`). Минутный скрипт подбирает только то, что не успело выполниться (статус остался `PENDING`).

### 14.4. Раскраска журнала сделок

**Строка целиком:**
| Состояние | Цвет |
|---|---|
| По умолчанию (pending/cancelled общий случай) | серый |
| Отменённые | тёмно-серый |
| Открытые | светло-оранжевый |
| Закрытые с прибылью | светло-зелёный |
| Закрытые с убытком | светло-красный |

**Ячейка `Тикер` (отдельно):**
- по умолчанию — без выделения,
- если по позиции **сработало усреднение** — жёлтый,
- если **нереализованный убыток ≥ `ui.alert_loss_pct`% от депозита** (по умолчанию 3%) — светло-красный (приоритет над жёлтым).

### 14.5. Сводная статистика (отдельная таблица)

Периоды:
- **Недели** — с субботы по пятницу включительно (локальная TZ Europe/Moscow).
- **Месяцы** — календарные.

Колонки:
- `Период` (с даты по дату),
- `Депозит на начало периода`,
- `Зафиксированная P/L за период (USDT)`,
- `% от депозита на начало периода`.

Источник данных: закрытые сделки с `closed_at` в диапазоне периода + `deposit_snapshots`.

### 14.6. Цветовая шкала депозита

Считается от `paper_initial_deposit_usdt` (для paper) или первой записи `deposit_snapshots` (для live/testnet) — это `D0`.

| Текущий / D0 | Цвет |
|---|---|
| ≥ 0.8 | зелёный |
| 0.5..0.8 | светло-зелёный |
| 0.3..0.5 | жёлтый |
| 0.1..0.3 | светло-красный |
| < 0.1 | красный |

Текущий депозит = `walletBalance + плавающий_PnL − ожидаемые_закрывающие_комиссии`.

---

## 15. Алерты и мониторинг

- **Telegram-алерты** уровней `ERROR` и `CRITICAL`: ошибки API, отрыв сигналов, drift времени, исполнение SL, активация дневного стопа, переключение в `live`.
- **Health-check endpoint** `/healthz` — возвращает 200 при свежих cron-запусках (последний `hourly` ≤ 70 мин назад, последний `minute` ≤ 90 сек назад). Можно мониторить через UptimeRobot.
- **Ежедневный отчёт в Telegram** в 09:00 локального времени: P/L день, число сделок, win-rate, текущий депозит, активные позиции.

---

## 16. ASSUMPTION-метки (на финальное ревью)

1. **Формула размера лота** — подтверждена в Утончении-3: `unleveraged / leverage`, `qty_coins = order_qty_usdt / entry_ref` (без умножения на плечо).
2. **`delta_pct_of_amplitude = 0.08`** — подтверждено.
3. **Funding/комиссии в `P_BE`** — taker для всех ног (conditional исполняется как market).
4. **Минимальный лот биржи в обратную сторону** (когда наш лот меньше qtyMin) — применяется тот же механизм `min_lot_overshoot`.
5. **SL после усреднения** двигается только в "лучшую" сторону — подтверждено.
6. **Старт часового скрипта** — `HH:01:00` — подтверждено.
7. **Считать активные ордера на уровне аккаунта** (включая ручные позиции/ордера) — да, как требовано; но пользовательские (не наши) ордера мы **не трогаем**.
8. **Стратегии в режиме `paper`** — разрешены все. Подтверждено.

---

## 17. Открытые вопросы для следующего этапа

Всё подтверждено в Утончении-3 (с поправкой v0.2.1 от 09.05.2026): стек зафиксирован — PHP 7.4 / Apache 2.4.41 / SQLite / Slim 4 / Twig 3.4–3.9 / Composer 2.x. Бэкапы — вручную пользователем.

---

## 18. Стратегии (модули)

### 18.1. Strategy 1 — автоматическая по signalsHourly

Источник намерений: `data/signals/YYYY-MM-DD_HH.json` (копия первоисточника).
Логика входа, ведения и закрытия — §3–§7 spec.md.
`isAutomatic = true`.
`onPositionOpened()` — стандартная (§6).

### 18.2. Strategy 2 — ручная по абсолютным ценам

`isAutomatic = false`. Создаётся **только** через UI.

#### 18.2.1. Пользовательский ввод
Поля формы:
- `symbol` (выбор из списка USDT-perp Bybit),
- `entry_price` — абсолютная цена условного ордера,
- `tp_price` — абсолютная цена TP,
- `sl_price` — абсолютная цена SL.

#### 18.2.2. Определение стороны и валидация
- Сторона **выводится** автоматически: `tp > entry → long`, `tp < entry → short`. Если знаки `(tp-entry)` и `(entry-sl)` не противоположны — ошибка валидации.
- Все три значения округляются к `tickSize` инструмента (для `entry`/`tp` — "внутрь", т.е. ближе к рынку, для `sl` — "наружу").

#### 18.2.3. Расчёт размера ордера
Используется тот же алгоритм, что и в S1, но `p_TP` берётся из введённых цен:
```
p_TP = |tp_price − entry_price| / entry_price × 100
```
Дальнейшие шаги — `base_lot → unleveraged → order_qty_usdt → order_qty_coins` — идентичны §5.4.

#### 18.2.4. Защиты при создании
Все защиты в режиме **`warn`** (см. §9.1). Перед отправкой conditional на биржу UI показывает модальное окно с перечнем сработавших защит и кнопкой "подтвердить". `daily_drawdown` выделяется отдельно как критическая.

#### 18.2.5. Действия после исполнения conditional
1. Определить `p_SL = |entry_real − sl_price| / entry_real × 100`. **`p_SL` сохраняется в `trades.p_for_strategy_calc`**, и далее **все** формулы S1 (SL, trailing, усреднение, BE) используют `p = p_SL` вместо `p = signal_target`.
2. Если `|tp_price − entry_real| ≠ |entry_real − sl_price|` (с допуском tickSize × 2) — выставить **дополнительный reduce-only лимит-ордер** на `0.6 × qty_initial` по `tp_price`. ID сохраняется в `trades.order_link_id_part_tp`.
3. Стандартные действия §6: `setTradingStop` (trailing вместо TP — но **не для всего объёма**, см. ниже), пересчёт SL по реальной цене, постановка усреднения.

**Важно:** после частичного исполнения 60%-TP объём позиции уменьшается. `qty_current` обновляется в БД. Когда срабатывают триггеры пересчёта (TS-движение, усреднение):
- усреднение пересчитывается от `qty_current` (а не от `qty_initial`): `qty_avg = qty_current × 2 × market_coef`,
- SL перенастраивается так, чтобы **общий риск** относительно текущего объёма не превышал заложенный.

#### 18.2.6. Закрытие
При `CLOSED_*` — снять `order_link_id_part_tp` (если ещё висит) и ордер усреднения.

### 18.3. Strategy 3 — ручная по entry + расстояние в %

`isAutomatic = false`. Создаётся через UI.

#### 18.3.1. Пользовательский ввод
- `symbol`,
- `side` (long/short — выбирает пользователь явно, потому что цены TP/SL из ввода не определяются),
- `entry_price` — абсолютная,
- `distance_pct` — расстояние до SL и TP (одинаковое, как в S1).

#### 18.3.2. Расчёт
- `tp_price = entry × (1 + sign × distance_pct/100)`,
- `sl_price = entry × (1 − sign × distance_pct/100)`,
- `p = distance_pct` — используется во всех формулах §5–§7 (как в S1).
- `p_for_strategy_calc = distance_pct`.

#### 18.3.3. Двунаправленные ордера на одном тикере
Допустимо одновременно держать **до двух** S3-conditional на один символ — **по одному в каждую сторону** (один long + один short). Третий S3 на тот же символ отклоняется.

**Конфликты с другими стратегиями:**
- Если по тикеру **открыта позиция** (любой стратегии) — новый S3-conditional противоположной стороны блокируется (UTA One-Way режим не позволит держать обе позиции одновременно).
- Если по тикеру висит **S1-conditional** и пользователь хочет поставить S3:
  - **разнонаправленный** → разрешено,
  - **однонаправленный** → блокируется с предложением "отменить S1 и поставить S3".

#### 18.3.4. Действия после исполнения conditional
1. Все остальные **S3-conditional на этот же символ** автоматически отменяются (выполняется в `cron_minute`, шаг 4).
2. Открытая позиция переходит в стандартное ведение S1 (§6) с `p = p_for_strategy_calc`.

#### 18.3.5. Защиты
Как у S2 — все в режиме `warn`, модальное подтверждение в UI.
