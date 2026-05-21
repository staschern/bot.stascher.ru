# Установка Bybit Futures Bot на VPS (Этап 1, PHP 7.4)

> Версия: 0.1.1-stage1-php74 — каркас без торговой логики, адаптирован под PHP 7.4.
> Цель этапа: убедиться, что инфраструктура (Apache + PHP 7.4 + SQLite + cron + 2FA-логин) работает.
> Реальная торговая логика добавляется в Этапе 2.

## 1. Требования

| Компонент | Версия | Проверка |
|---|---|---|
| OS | Ubuntu 20.04 (focal) | `lsb_release -a` |
| Apache | 2.4.41 | `apache2 -v` |
| PHP (CLI + модуль Apache / PHP-FPM) | **7.4** | `php -v` |
| Composer | 2.x | `composer --version` |
| SQLite | 3.31+ | `sqlite3 --version` |
| Cron | стандартный | `service cron status` |

Нужны расширения PHP 7.4: `pdo`, `pdo_sqlite`, `curl`, `mbstring`, `openssl`, `json`, `xml`, `intl`, `tokenizer`. Большинство уже стоит на ISPmanager-серверах.

Если каких-то пакетов не хватает — доставить из стандартных репозиториев Ubuntu 20.04 (без подключения сторонних PPA, чтобы не ломать действующие сайты):

```bash
sudo apt-get update
sudo apt-get install -y \
    php7.4-cli php7.4-sqlite3 php7.4-curl php7.4-mbstring \
    php7.4-xml php7.4-intl php7.4-tokenizer
# при необходимости (если Apache использует mod_php):
# sudo apt-get install -y libapache2-mod-php7.4
sudo a2enmod rewrite headers
```

Проверка установленных расширений:

```bash
php -m | grep -E 'pdo_sqlite|mbstring|curl|openssl|intl|tokenizer|xml'
```

> ⚠ **Не пытайтесь устанавливать PHP 8.x через `ondrej/php` для focal** — пакеты PHP 8.x для focal удалены из PPA, а sury.org возвращает HTTP 418. Если когда-нибудь понадобится PHP 8.x, понадобится либо обновление до Ubuntu 22.04+, либо полностью отдельная сборка.

## 2. Распаковка проекта

Допустим, целевая директория — `/var/www/bybit-bot/`.

```bash
sudo mkdir -p /var/www/bybit-bot
sudo chown $USER:$USER /var/www/bybit-bot
cd /var/www/bybit-bot
unzip /path/to/bybit-bot-stage1.zip -d .
```

## 3. Установка зависимостей

```bash
cd /var/www/bybit-bot
composer install --no-dev --optimize-autoloader
```

> ⚠ В архиве каталог `vendor/` отсутствует — это сделано намеренно, чтобы не раздувать архив.
> Все зависимости (Slim 4, Twig 3.4–3.9, Guzzle 7, Monolog 2, OTPHP 10, phpdotenv 5, ramsey/uuid 4) ставятся через `composer install`. Версии в `composer.json` подобраны совместимыми с PHP 7.4 — не обновляйте их вручную до мажорных версий, требующих PHP 8.

## 4. Настройка `.env`

```bash
cp .env.example .env
nano .env
```

Минимум, что нужно поправить:
- `DB_PATH=/var/www/bybit-bot/data/bot.db`
- `SECRETS_DIR=/var/www/bybit-bot/data/secrets`
- `SIGNALS_LOCAL_DIR=/var/www/bybit-bot/data/signals`
- `LOGS_DIR=/var/www/bybit-bot/logs`
- `SIGNALS_SOURCE_PATH=/var/www/stascher.ru/finManager/shared/signalsHourly.json`

## 5. Права на каталоги

Apache в Ubuntu обычно работает от пользователя `www-data`. Каталог `data/` и `logs/`
должны быть доступны на запись.

```bash
sudo chown -R www-data:www-data /var/www/bybit-bot/data /var/www/bybit-bot/logs
sudo chmod 700 /var/www/bybit-bot/data/secrets
sudo chmod 750 /var/www/bybit-bot/data /var/www/bybit-bot/logs
```

Если cron-скрипты будут запускаться от другого пользователя (например, root через `/etc/cron.d/`),
дайте им доступ через ACL или общую группу.

## 6. Применение миграций и засев дефолтов

```bash
cd /var/www/bybit-bot
sudo -u www-data php bin/cli.php migrate
```

Ожидаемый вывод:
```
Применены миграции:
  - 001_initial_schema
Дефолты настроек и стратегии засеяны.
```

Файл БД создаётся в `data/bot.db`.

## 7. Создание пользователя UI с TOTP

```bash
sudo -u www-data php bin/cli.php auth:init
```

Скрипт спросит логин и пароль (≥ 8 символов), сгенерирует TOTP-секрет и покажет:
- TOTP-секрет (для ручного ввода);
- Provisioning URL `otpauth://...` — можно открыть в Google Authenticator/2FAS/Authy через ввод ключа.

> ⚠ Секрет показан **один раз**. Сохраните в надёжном месте (менеджер паролей).

## 8. Apache vhost

Пример конфига `/etc/apache2/sites-available/bybit-bot.conf`:

```apache
<VirtualHost *:443>
    ServerName bot.example.com

    DocumentRoot /var/www/bybit-bot/public

    <Directory /var/www/bybit-bot/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Запрет прямого доступа ко всему вне public/
    <Directory /var/www/bybit-bot/data>
        Require all denied
    </Directory>
    <Directory /var/www/bybit-bot/config>
        Require all denied
    </Directory>
    <Directory /var/www/bybit-bot/logs>
        Require all denied
    </Directory>

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/bot.example.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/bot.example.com/privkey.pem

    ErrorLog  ${APACHE_LOG_DIR}/bybit-bot-error.log
    CustomLog ${APACHE_LOG_DIR}/bybit-bot-access.log combined
</VirtualHost>

<VirtualHost *:80>
    ServerName bot.example.com
    Redirect permanent / https://bot.example.com/
</VirtualHost>
```

```bash
sudo a2ensite bybit-bot
sudo apache2ctl configtest
sudo systemctl reload apache2
```

После этого открывается `https://bot.example.com/login` — страница входа.
После входа — `/` с дашбордом-заглушкой.

## 9. Cron

Файл `/etc/cron.d/bybit-bot`:

```cron
# m h dom mon dow user cmd

# Часовой запуск: HH:01:00 (через минуту после генерации signalsHourly.json)
1 *  * * * www-data /usr/bin/php7.4 /var/www/bybit-bot/bin/cron_hourly.php >> /var/www/bybit-bot/logs/cron_hourly.out 2>&1

# Минутный запуск каждую минуту
* *  * * * www-data /usr/bin/php7.4 /var/www/bybit-bot/bin/cron_minute.php >> /var/www/bybit-bot/logs/cron_minute.out 2>&1

# Ежедневный запуск 00:00 UTC (3:00 MSK)
0 0  * * * www-data /usr/bin/php7.4 /var/www/bybit-bot/bin/cron_daily.php >> /var/www/bybit-bot/logs/cron_daily.out 2>&1
```

> ⚠ В Этапе 1 cron-скрипты — заглушки. Они корректно завершаются и записывают факт запуска в `cron_runs`,
> но реальной работы (импорт сигналов, постановка ордеров) ещё не выполняют. Это будет в Этапе 2.

## 10. Проверка установки

1. Откройте `https://bot.example.com/healthz` — JSON со статусом cron-запусков.
   В первые 70 минут после установки `hourly` будет `degraded` (ещё не запускался — нормально).
2. Откройте `https://bot.example.com/login`, введите логин/пароль/TOTP — должны попасть на дашборд.
3. Через 5 минут после установки cron проверьте `tail -f /var/www/bybit-bot/logs/app.log` —
   должны появиться записи `cron_minute: slot=...`.
4. Проверьте, что в `cron_runs` появляются записи:
   ```bash
   sqlite3 /var/www/bybit-bot/data/bot.db "SELECT kind, slot, status, started_at FROM cron_runs ORDER BY id DESC LIMIT 10;"
   ```

## 11. Бэкап

> Бэкапы выполняются вручную по решению пользователя (см. spec.md §17).

Минимальный набор для бэкапа:
- `/var/www/bybit-bot/data/bot.db` (можно через `sqlite3 .backup /path/to/copy.db`)
- `/var/www/bybit-bot/data/secrets/` (TOTP-секреты, API-ключи)
- `/var/www/bybit-bot/.env`
- `/var/www/bybit-bot/data/signals/` (история JSON-сигналов, по желанию)

## 12. Что дальше (Этап 2)

После того как Этап 1 успешно поднят, будут добавлены:
- ExchangeAdapter (Bybit V5 REST, HMAC, retry).
- Импорт сигналов и формула расчёта ордера (S1).
- Полный цикл сделки: PENDING → OPEN → AVERAGED → CLOSED.
- Журнал сделок и событий в UI.
- Цветовая шкала депозита и сводная статистика.
- Telegram-алерты.
