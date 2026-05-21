<?php
declare(strict_types=1);

namespace BybitBot\Core;

use PDOException;

/**
 * Anti-rerun guard для cron-скриптов через таблицу `cron_runs`.
 *
 * Использует UNIQUE INDEX на (kind, slot): попытка вставки дубликата → false.
 *
 * См. spec.md §2.2 и §3.1 (шаг 1: anti-rerun).
 *
 * Схема использования:
 *   $guard = new CronGuard('hourly', '2026-05-08 10');
 *   if (!$guard->begin()) { exit(0); }      // уже выполняется/выполнено
 *   try {
 *       // ... работа ...
 *       $guard->success('ok');
 *   } catch (\Throwable $e) {
 *       $guard->fail($e->getMessage());
 *       throw $e;
 *   }
 */
final class CronGuard
{
    /** @var int|null */
    private $rowId = null;

    /** @var string */
    private $kind;

    /** @var string */
    private $slot;

    /**
     * @param string $kind 'hourly'|'minute'|'daily'
     * @param string $slot '2026-05-08 10' или '2026-05-08 10:31' или '2026-05-08'
     */
    public function __construct(string $kind, string $slot)
    {
        $this->kind = $kind;
        $this->slot = $slot;
    }

    /**
     * Пытается зарегистрировать запуск. Возвращает false, если для этого слота
     * уже есть запись (запуск был или идёт).
     */
    public function begin(): bool
    {
        $pdo = Database::pdo();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO cron_runs (kind, slot, status, started_at) VALUES (:k, :s, :st, :ts)'
            );
            $stmt->execute([
                ':k'  => $this->kind,
                ':s'  => $this->slot,
                ':st' => 'started',
                ':ts' => self::nowIso(),
            ]);
            $this->rowId = (int)$pdo->lastInsertId();
            return true;
        } catch (PDOException $e) {
            // UNIQUE constraint failed → дубликат — не запускаем повторно.
            if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                return false;
            }
            throw $e;
        }
    }

    public function success(?string $message = null): void
    {
        $this->finish('success', $message);
    }

    public function fail(?string $message = null): void
    {
        $this->finish('failed', $message);
    }

    private function finish(string $status, ?string $message): void
    {
        if ($this->rowId === null) {
            return;
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE cron_runs SET status = :st, finished_at = :ts, message = :m WHERE id = :id'
        );
        $stmt->execute([
            ':st'  => $status,
            ':ts'  => self::nowIso(),
            ':m'   => $message,
            ':id'  => $this->rowId,
        ]);
    }

    private static function nowIso(): string
    {
        $t = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\TH:i:s.', (int)$t) . $micro . 'Z';
    }

    /** Утилита для генерации слота "YYYY-MM-DD HH" в UTC. */
    public static function slotHourly(?int $unixTs = null): string
    {
        return gmdate('Y-m-d H', $unixTs ?? time());
    }

    public static function slotMinute(?int $unixTs = null): string
    {
        return gmdate('Y-m-d H:i', $unixTs ?? time());
    }

    public static function slotDaily(?int $unixTs = null): string
    {
        return gmdate('Y-m-d', $unixTs ?? time());
    }
}
