<?php
declare(strict_types=1);

namespace BybitBot\Core;

use PDO;
use RuntimeException;

/**
 * Минималистичный мигратор: применяет .sql-файлы из data/migrations по порядку имени.
 * Версия = имя файла без .sql. Записи в `schema_migrations`.
 *
 * Использование: php bin/cli.php migrate
 */
final class Migrator
{
    /** @var string */
    private $migrationsDir;

    public function __construct(string $migrationsDir)
    {
        $this->migrationsDir = $migrationsDir;
    }

    /** @return string[] список применённых версий */
    public function migrate(): array
    {
        if (!is_dir($this->migrationsDir)) {
            throw new RuntimeException("Каталог миграций не найден: {$this->migrationsDir}");
        }

        $pdo = Database::pdo();

        // Сначала создаём таблицу schema_migrations, если её нет (на чистой БД).
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version    TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL
        )');

        $applied = [];
        $rows = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $alreadyApplied = array_flip($rows);

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (isset($alreadyApplied[$version])) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Не удалось прочитать миграцию: {$file}");
            }

            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $stmt = $pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:v, :t)'
                );
                $stmt->execute([
                    ':v' => $version,
                    ':t' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new RuntimeException("Ошибка применения миграции {$version}: " . $e->getMessage(), 0, $e);
            }

            $applied[] = $version;
        }

        return $applied;
    }

    /** Заливает дефолтные значения настроек и стратегий, если их ещё нет. */
    public function seedDefaults(array $defaults, array $strategies): void
    {
        $pdo = Database::pdo();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        // settings — только если ключа ещё нет.
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (:k, :v, :u)'
        );
        foreach ($defaults as $key => $val) {
            $stringVal = is_scalar($val) || $val === null
                ? (string)$val
                : json_encode($val, JSON_UNESCAPED_UNICODE);
            $stmt->execute([':k' => $key, ':v' => $stringVal, ':u' => $now]);
        }

        // strategies — только при отсутствии.
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO strategies (id, name, enabled, is_automatic, description, updated_at)
             VALUES (:id, :n, :en, :auto, :d, :u)'
        );
        foreach ($strategies as $id => $cfg) {
            $stmt->execute([
                ':id'   => $id,
                ':n'    => $cfg['name'],
                ':en'   => !empty($cfg['enabled']) ? 1 : 0,
                ':auto' => !empty($cfg['is_automatic']) ? 1 : 0,
                ':d'    => $cfg['description'] ?? null,
                ':u'    => $now,
            ]);
        }
    }
}
