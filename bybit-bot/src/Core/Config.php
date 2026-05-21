<?php
declare(strict_types=1);

namespace BybitBot\Core;

use RuntimeException;

/**
 * Двухуровневая загрузка конфигурации:
 *  1. Bootstrap — config/settings.php (+ .env)
 *  2. БД — таблица `settings` (приоритет) + `strategy_settings` для override.
 *
 * См. spec.md §9.2 и §13.
 */
final class Config
{
    /** @var array */
    private static $bootstrap = [];

    /** @var array<string,string> key => value (актуальный для текущего процесса) */
    private static $dbCache = [];

    /** @var array<string,array<string,string>> strategy_id => [key => value] */
    private static $stratCache = [];

    /** @var bool */
    private static $dbLoaded = false;

    public static function loadBootstrap(string $configPath): void
    {
        if (!is_file($configPath)) {
            throw new RuntimeException("Не найден файл конфигурации: {$configPath}");
        }
        /** @var array $cfg */
        $cfg = require $configPath;
        self::$bootstrap = $cfg;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function bootstrap(string $key, $default = null)
    {
        // Поддержка точечной нотации: 'paths.db', 'bybit.recv_window'
        $parts = explode('.', $key);
        $cur = self::$bootstrap;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return $default;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }

    public static function bootstrapAll(): array
    {
        return self::$bootstrap;
    }

    /**
     * Получить значение настройки. Приоритет: strategy_settings (если задан strategy_id) → settings → defaults.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, ?string $strategyId = null, $default = null)
    {
        self::ensureDbLoaded();

        if ($strategyId !== null && isset(self::$stratCache[$strategyId][$key])) {
            return self::castValue(self::$stratCache[$strategyId][$key]);
        }
        if (array_key_exists($key, self::$dbCache)) {
            return self::castValue(self::$dbCache[$key]);
        }
        $defaults = self::$bootstrap['defaults'] ?? [];
        if (array_key_exists($key, $defaults)) {
            return $defaults[$key];
        }
        return $default;
    }

    /**
     * @param mixed $value
     */
    public static function set(string $key, $value, ?string $strategyId = null): void
    {
        $pdo = Database::pdo();
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $val = is_scalar($value) || $value === null
            ? (string)$value
            : json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($strategyId !== null) {
            $stmt = $pdo->prepare(
                'INSERT INTO strategy_settings (strategy_id, key, value, updated_at)
                 VALUES (:s, :k, :v, :u)
                 ON CONFLICT(strategy_id, key) DO UPDATE SET value = :v, updated_at = :u'
            );
            $stmt->execute([':s' => $strategyId, ':k' => $key, ':v' => $val, ':u' => $now]);
            self::$stratCache[$strategyId][$key] = $val;
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO settings (key, value, updated_at)
                 VALUES (:k, :v, :u)
                 ON CONFLICT(key) DO UPDATE SET value = :v, updated_at = :u'
            );
            $stmt->execute([':k' => $key, ':v' => $val, ':u' => $now]);
            self::$dbCache[$key] = $val;
        }
    }

    public static function reloadDb(): void
    {
        self::$dbLoaded = false;
        self::ensureDbLoaded();
    }

    private static function ensureDbLoaded(): void
    {
        if (self::$dbLoaded) {
            return;
        }
        $pdo = Database::pdo();

        $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll();
        self::$dbCache = [];
        foreach ($rows as $r) {
            self::$dbCache[$r['key']] = $r['value'];
        }

        $rows = $pdo->query('SELECT strategy_id, key, value FROM strategy_settings')->fetchAll();
        self::$stratCache = [];
        foreach ($rows as $r) {
            self::$stratCache[$r['strategy_id']][$r['key']] = $r['value'];
        }

        self::$dbLoaded = true;
    }

    /**
     * @return mixed
     */
    private static function castValue(string $raw)
    {
        // Простая авто-конвертация типов для значений из БД.
        if ($raw === '')        return '';
        if ($raw === 'true')    return true;
        if ($raw === 'false')   return false;
        if ($raw === 'null')    return null;
        if (is_numeric($raw)) {
            return strpos($raw, '.') !== false ? (float)$raw : (int)$raw;
        }
        $first = $raw[0] ?? '';
        if ($first === '{' || $first === '[') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $raw;
    }
}
