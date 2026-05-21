<?php
declare(strict_types=1);

namespace BybitBot\Strategies;

use BybitBot\Core\Database;
use RuntimeException;

/**
 * Реестр стратегий. См. spec.md §18.
 *
 * Источник истины — config/strategies.php (классы) + таблица `strategies` (включённость в БД).
 * При несовпадении приоритет у БД (можно отключить S2/S3 в UI).
 */
final class StrategyRegistry
{
    /** @var array<string, StrategyInterface> */
    private array $instances = [];

    /** @var array<string, array{class:string,name:string,enabled:bool,is_automatic:bool,description?:string}> */
    private array $config;

    /**
     * @param array<string, array{class:string,name:string,enabled:bool,is_automatic:bool,description?:string}> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** Все стратегии (без фильтра). */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->config) as $id) {
            $out[$id] = $this->get($id);
        }
        return $out;
    }

    /** Только включённые в БД стратегии. */
    public function enabled(): array
    {
        $enabled = $this->loadEnabledFromDb();
        $out = [];
        foreach ($enabled as $id) {
            if (isset($this->config[$id])) {
                $out[$id] = $this->get($id);
            }
        }
        return $out;
    }

    /** Только включённые автоматические стратегии — для cron_hourly. */
    public function enabledAutomatic(): array
    {
        $out = [];
        foreach ($this->enabled() as $id => $strat) {
            if ($strat->isAutomatic()) {
                $out[$id] = $strat;
            }
        }
        return $out;
    }

    public function get(string $id): StrategyInterface
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!isset($this->config[$id])) {
            throw new RuntimeException("Стратегия не найдена в конфиге: {$id}");
        }
        $class = $this->config[$id]['class'];
        if (!class_exists($class)) {
            throw new RuntimeException("Класс стратегии не существует: {$class}");
        }
        $instance = new $class();
        if (!$instance instanceof StrategyInterface) {
            throw new RuntimeException("Класс {$class} не реализует StrategyInterface");
        }
        $this->instances[$id] = $instance;
        return $instance;
    }

    /** @return string[] Список id включённых стратегий из таблицы `strategies`. */
    private function loadEnabledFromDb(): array
    {
        try {
            $stmt = Database::pdo()->query('SELECT id FROM strategies WHERE enabled = 1');
            return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\PDOException $e) {
            // Таблицы ещё нет (миграции не применены) — fallback на конфиг.
            return array_keys(array_filter($this->config, static fn($c) => !empty($c['enabled'])));
        }
    }
}
