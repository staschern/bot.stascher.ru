<?php
declare(strict_types=1);

namespace BybitBot\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Singleton-обёртка над PDO для SQLite.
 *
 * См. spec.md §12 — все таблицы. WAL-режим для возможности конкурентного чтения
 * во время записи (cron + UI одновременно).
 */
final class Database
{
    /** @var PDO|null */
    private static $pdo = null;

    /** @var string|null */
    private static $path = null;

    public static function init(string $dbPath): void
    {
        self::$path = $dbPath;
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог БД: {$dir}");
        }

        try {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException("Не удалось открыть SQLite: " . $e->getMessage(), 0, $e);
        }

        // Прагмы — производительность и надёжность.
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        // v0.8.0.17: повышен до 30 сек, чтобы CLI-скрипты (diag/force_recalc/cron)
        // не падали из-за коротких блокировок от параллельных вхождений cron_minute/hourly.
        $pdo->exec('PRAGMA busy_timeout=30000');
        $pdo->exec('PRAGMA temp_store=MEMORY');

        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database::init() не вызван — БД не инициализирована.');
        }
        return self::$pdo;
    }

    public static function path(): string
    {
        if (self::$path === null) {
            throw new RuntimeException('Database::init() не вызван.');
        }
        return self::$path;
    }

    /**
     * Транзакция с автоматическим коммитом/откатом.
     *
     * @param callable $fn function(PDO $pdo): mixed
     * @return mixed
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function reset(): void
    {
        self::$pdo  = null;
        self::$path = null;
    }
}
