<?php
declare(strict_types=1);

namespace BybitBot\Core;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;

/**
 * Фабрика логгеров. Файлы — по дням, ротация 14 дней.
 *
 * Кроме файлового лога, важные события дублируются в БД — таблицы `events`/`trade_events`,
 * запись делается в EventRecorder отдельно (см. §12.1).
 *
 * ВАЖНО: используем Monolog 2.x (Monolog 3.x требует PHP 8.1+).
 */
final class Logger
{
    /** @var LoggerInterface|null */
    private static $appLogger = null;

    public static function init(string $logsDir, bool $debug = false): void
    {
        if (!is_dir($logsDir) && !mkdir($logsDir, 0755, true) && !is_dir($logsDir)) {
            throw new \RuntimeException("Не удалось создать каталог логов: {$logsDir}");
        }

        $logger = new MonologLogger('app');

        $fileHandler = new RotatingFileHandler(
            $logsDir . '/app.log',
            14,
            $debug ? MonologLogger::DEBUG : MonologLogger::INFO
        );
        $fileHandler->setFormatter(new LineFormatter(
            "[%datetime%] %level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,  // allowInlineLineBreaks
            true   // ignoreEmptyContextAndExtra
        ));
        $logger->pushHandler($fileHandler);

        // CLI/cron — также в stderr, чтобы cron mail-alerts работали.
        if (PHP_SAPI === 'cli') {
            $stderr = new StreamHandler('php://stderr', MonologLogger::WARNING);
            $stderr->setFormatter(new LineFormatter(
                "[%datetime%] %level_name%: %message% %context%\n",
                'Y-m-d H:i:s',
                true,
                true
            ));
            $logger->pushHandler($stderr);
        }

        self::$appLogger = $logger;
    }

    public static function get(): LoggerInterface
    {
        if (self::$appLogger === null) {
            throw new \RuntimeException('Logger::init() не вызван.');
        }
        return self::$appLogger;
    }
}
