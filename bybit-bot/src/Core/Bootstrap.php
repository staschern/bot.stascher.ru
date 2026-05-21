<?php
declare(strict_types=1);

namespace BybitBot\Core;

use Dotenv\Dotenv;

/**
 * Инициализация окружения для CLI и Web. Вызывается из public/index.php и bin/*.php.
 *
 * Что делает:
 *  1. Загружает Composer autoload.
 *  2. Подгружает .env (если есть).
 *  3. Загружает config/settings.php → bootstrap.
 *  4. Открывает БД, инициализирует Logger.
 */
final class Bootstrap
{
    public static function init(string $rootDir): void
    {
        $autoload = $rootDir . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            fwrite(STDERR, "Composer autoload не найден. Запустите: composer install\n");
            exit(1);
        }
        require_once $autoload;

        // .env (опционально)
        if (is_file($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->safeLoad();
        }

        // Bootstrap-конфиг
        Config::loadBootstrap($rootDir . '/config/settings.php');

        // v0.8.0: подгружаем live API ключи из data/secrets/live_keys.php
        // (после .env, после Config::loadBootstrap — нужен путь paths.secrets).
        \BybitBot\Bybit\SecretsService::loadIntoEnv();

        // Таймзона
        $tz = Config::bootstrap('app.timezone', 'Europe/Moscow');
        date_default_timezone_set($tz);

        // Логи
        Logger::init(
            (string)Config::bootstrap('paths.logs'),
            (bool)Config::bootstrap('app.debug', false),
        );

        // БД
        Database::init((string)Config::bootstrap('paths.db'));

        // Не загружаем настройки из БД здесь — это делает Config::get() лениво.
        // (При первом запуске cli.php migrate БД может быть пустой.)
    }
}
