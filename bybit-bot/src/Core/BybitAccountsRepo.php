<?php
declare(strict_types=1);

namespace BybitBot\Core;

use PDO;
use RuntimeException;

/**
 * Репозиторий для bybit_accounts (v0.9.0 — multi-account).
 *
 * Чистый CRUD + бизнес-правила:
 *   * имя UNIQUE (case-sensitive);
 *   * нельзя архивировать аккаунт с открытыми trades;
 *   * enabled влияет только на открытие НОВЫХ ордеров — старые позиции
 *     по выключенному аккаунту по-прежнему сопровождаются крон-задачами,
 *     которые итерируют ПО ВСЕМ account_id с открытыми trades.
 *
 * Секреты (api_key/api_secret) лежат в файлах data/secrets/account_{id}.php
 * и в этой таблице не отражаются (только last4-маска для UI).
 *
 * Все методы — статические, как и в остальной кодовой базе (Database, EventRecorder).
 */
final class BybitAccountsRepo
{
    public const NETWORK_LIVE    = 'live';
    public const NETWORK_TESTNET = 'testnet';

    public const RISK_CONSERVATIVE = 'conservative';
    public const RISK_STANDARD     = 'standard';

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function listAll(bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM bybit_accounts';
        if (!$includeArchived) {
            $sql .= ' WHERE archived_at IS NULL';
        }
        $sql .= ' ORDER BY id ASC';
        $rows = Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'normalizeRow'], $rows);
    }

    /**
     * Аккаунты, подходящие по network с master switch:
     *   * enabled=1;
     *   * archived_at IS NULL;
     *   * network матчит сеть.
     *
     * Применяется, когда нужен просто список «вообще включённых» (например в эквити:
     * суммировать балансы). Для стратегий используйте getEnabledForNetworkAndStrategy().
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getEnabledForNetwork(string $network): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM bybit_accounts
             WHERE enabled = 1
               AND archived_at IS NULL
               AND network = :net
             ORDER BY id ASC'
        );
        $stmt->execute([':net' => $network]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'normalizeRow'], $rows);
    }

    /**
     * v0.9.0-step7: аккаунты, включённые и в мастер-свитч (enabled=1),
     * и в конкретной стратегии (sN_enabled=1).
     *
     * Используется в fan-out s1 (cron_hourly) и в списке аккаунтов для ручного открытия s2.
     *
     * @param string $network   live|testnet
     * @param string $strategyId 's1'|'s2'|'s3'
     * @return array<int,array<string,mixed>>
     */
    public static function getEnabledForNetworkAndStrategy(string $network, string $strategyId): array
    {
        $col = self::strategyColumn($strategyId);
        if ($col === null) {
            // Неизвестная стратегия — фолбэк на обычный enabled (лучше пустить, чем сломать).
            return self::getEnabledForNetwork($network);
        }
        $sql = 'SELECT * FROM bybit_accounts
                 WHERE enabled = 1
                   AND ' . $col . ' = 1
                   AND archived_at IS NULL
                   AND network = :net
                 ORDER BY id ASC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':net' => $network]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'normalizeRow'], $rows);
    }

    /**
     * v0.9.0-step7: обновить per-strategy флаг аккаунта.
     *
     * @param string $strategyId 's1'|'s2'|'s3'
     */
    /**
     * Установить режим риска для аккаунта (v0.9.1).
     * Влияет на формулу movement_coef в §6.3 (trailing stop).
     */
    public static function setRiskMode(int $id, string $riskMode): void
    {
        if (!in_array($riskMode, [self::RISK_CONSERVATIVE, self::RISK_STANDARD], true)) {
            throw new RuntimeException("risk_mode должен быть 'conservative' или 'standard'");
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE bybit_accounts SET risk_mode = :rm, updated_at = :u WHERE id = :id'
        );
        $stmt->execute([
            ':rm' => $riskMode,
            ':u'  => gmdate('Y-m-d\\TH:i:s\\Z'),
            ':id' => $id,
        ]);
    }

    public static function setStrategyEnabled(int $id, string $strategyId, bool $enabled): void
    {
        $col = self::strategyColumn($strategyId);
        if ($col === null) {
            throw new RuntimeException("Неизвестная стратегия: '{$strategyId}'");
        }
        $stmt = Database::pdo()->prepare(
            "UPDATE bybit_accounts SET {$col} = :en, updated_at = :u WHERE id = :id"
        );
        $stmt->execute([
            ':en' => $enabled ? 1 : 0,
            ':u'  => gmdate('Y-m-d\\TH:i:s\\Z'),
            ':id' => $id,
        ]);
    }

    private static function strategyColumn(string $strategyId): ?string
    {
        switch ($strategyId) {
            case 's1': return 's1_enabled';
            case 's2': return 's2_enabled';
            case 's3': return 's3_enabled';
            default:   return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM bybit_accounts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByName(string $name): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM bybit_accounts WHERE name = :n');
        $stmt->execute([':n' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    /**
     * Создать запись аккаунта. Секреты сохраняются ОТДЕЛЬНО через SecretsService
     * (этот метод хранит только last4-маску для UI).
     *
     * @return int id созданного аккаунта
     */
    public static function create(string $name, string $network, string $apiKey, bool $enabled = true): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new RuntimeException('Имя аккаунта: 1..64 символа');
        }
        if (!in_array($network, [self::NETWORK_LIVE, self::NETWORK_TESTNET], true)) {
            throw new RuntimeException('network должен быть live|testnet');
        }
        if (self::findByName($name) !== null) {
            throw new RuntimeException("Аккаунт с именем '{$name}' уже существует");
        }

        $now = gmdate('Y-m-d\\TH:i:s\\Z');
        $stmt = Database::pdo()->prepare(
            'INSERT INTO bybit_accounts (name, network, enabled, api_key_mask, created_at, updated_at)
             VALUES (:n, :net, :en, :mask, :c, :u)'
        );
        $stmt->execute([
            ':n'    => $name,
            ':net'  => $network,
            ':en'   => $enabled ? 1 : 0,
            ':mask' => self::maskKey($apiKey),
            ':c'    => $now,
            ':u'    => $now,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function rename(int $id, string $newName): void
    {
        $newName = trim($newName);
        if ($newName === '' || mb_strlen($newName) > 64) {
            throw new RuntimeException('Имя аккаунта: 1..64 символа');
        }
        $other = self::findByName($newName);
        if ($other !== null && (int)$other['id'] !== $id) {
            throw new RuntimeException("Имя '{$newName}' уже занято другим аккаунтом");
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE bybit_accounts SET name = :n, updated_at = :u WHERE id = :id'
        );
        $stmt->execute([
            ':n'  => $newName,
            ':u'  => gmdate('Y-m-d\\TH:i:s\\Z'),
            ':id' => $id,
        ]);
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE bybit_accounts SET enabled = :en, updated_at = :u WHERE id = :id'
        );
        $stmt->execute([
            ':en' => $enabled ? 1 : 0,
            ':u'  => gmdate('Y-m-d\\TH:i:s\\Z'),
            ':id' => $id,
        ]);
    }

    public static function updateApiKeyMask(int $id, string $apiKey): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE bybit_accounts SET api_key_mask = :m, updated_at = :u WHERE id = :id'
        );
        $stmt->execute([
            ':m'  => self::maskKey($apiKey),
            ':u'  => gmdate('Y-m-d\\TH:i:s\\Z'),
            ':id' => $id,
        ]);
    }

    /**
     * Кол-во НЕЗАКРЫТЫХ trades по аккаунту. Используется при archive():
     * запрещаем soft-delete пока > 0.
     */
    public static function openTradesCount(int $id): int
    {
        // Открытыми считаются все статусы, кроме терминальных (см. spec.md §3.3).
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM trades
             WHERE account_id = :id
               AND status NOT IN ('CLOSED_PROFIT','CLOSED_LOSS','CANCELLED')"
        );
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Soft-delete: проставляет archived_at. Сам аккаунт остаётся в БД ради
     * историчности trades. После архивирования:
     *   * UI прячет его из списков (если includeArchived=false);
     *   * fan-out s1/s2 его не использует;
     *   * cron-ы по-прежнему сопровождают его открытые позиции, если такие есть
     *     (но при архивировании их не должно быть — мы это проверяем).
     */
    public static function archive(int $id): void
    {
        $acc = self::find($id);
        if ($acc === null) {
            throw new RuntimeException("Аккаунт #{$id} не найден");
        }
        if ($acc['archived_at'] !== null) {
            return; // уже архивирован
        }
        $open = self::openTradesCount($id);
        if ($open > 0) {
            throw new RuntimeException(
                "Нельзя архивировать аккаунт '{$acc['name']}': есть {$open} открытых позиций. "
                . 'Сначала закройте/отмените их.'
            );
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE bybit_accounts SET archived_at = :t, updated_at = :t WHERE id = :id'
        );
        $stmt->execute([
            ':t'  => gmdate('Y-m-d\\TH:i:s\\Z'),
            ':id' => $id,
        ]);
    }

    /**
     * Распаковать row в чистый array для использования в коде.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id'           => (int)$row['id'],
            'name'         => (string)$row['name'],
            'network'      => (string)$row['network'],
            'enabled'      => ((int)$row['enabled']) === 1,
            // v0.9.0-step7: per-strategy флаги. Дефолт = true для обратной совместимости
            // (если миграция 015 ещё не накатилась, колонок нет — всё включено).
            's1_enabled'   => array_key_exists('s1_enabled', $row) ? ((int)$row['s1_enabled']) === 1 : true,
            's2_enabled'   => array_key_exists('s2_enabled', $row) ? ((int)$row['s2_enabled']) === 1 : true,
            's3_enabled'   => array_key_exists('s3_enabled', $row) ? ((int)$row['s3_enabled']) === 1 : true,
            // v0.9.1: risk_mode — консервативный (2^k) или стандарт (floor(p/4)+1). Дефолт = conservative.
            'risk_mode'    => array_key_exists('risk_mode', $row) ? (string)$row['risk_mode'] : self::RISK_CONSERVATIVE,
            'api_key_mask' => (string)($row['api_key_mask'] ?? ''),
            'archived_at'  => $row['archived_at'] !== null ? (string)$row['archived_at'] : null,
            'created_at'   => (string)$row['created_at'],
            'updated_at'   => (string)$row['updated_at'],
        ];
    }

    private static function maskKey(string $apiKey): string
    {
        $len = strlen($apiKey);
        if ($len === 0) return '';
        if ($len <= 4) return str_repeat('*', $len);
        return str_repeat('*', max(0, $len - 4)) . substr($apiKey, -4);
    }
}
