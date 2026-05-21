<?php
declare(strict_types=1);

namespace BybitBot\Signals;

use BybitBot\Core\Database;
use BybitBot\Core\Logger;

/**
 * v0.7.7: Журнал сигналов — фиксация решений по каждому сигналу.
 *
 * Решения:
 *   - accepted             — сигнал прошёл фильтры/guards и создан trade
 *   - rejected_unresolved  — символ не сопоставлен с фьючерсом Bybit
 *   - rejected_filter      — отсечён фильтром filterSignal (стейблкоин, target<min, position_exists и т.д.)
 *   - rejected_guard       — заблокирован guard'ом (drawdown, funding, и т.д.)
 *   - rejected_duplicate   — для этого signal_id уже существует trade (идемпотентность)
 *   - rejected_other       — прочие ошибки (например build_failed)
 *
 * Все методы безопасны (try/catch): запись decision не должна ломать торговый pipeline.
 */
final class Decisions
{
    public const ACCEPTED            = 'accepted';
    public const REJECTED_UNRESOLVED = 'rejected_unresolved';
    public const REJECTED_FILTER     = 'rejected_filter';
    public const REJECTED_GUARD      = 'rejected_guard';
    public const REJECTED_DUPLICATE  = 'rejected_duplicate';
    public const REJECTED_OTHER      = 'rejected_other';

    /**
     * Зафиксировать решение по сигналу. Если decision уже стоит — НЕ перезаписываем
     * (первое решение в pipeline всегда «правильное», последующие — побочные эффекты).
     *
     * @param int      $signalId
     * @param string   $decision    одна из констант DECISION_*
     * @param string   $reason      свободный текст (имя guard, фильтра, error message)
     * @param int|null $tradeId     если decision='accepted'
     */
    public static function record(int $signalId, string $decision, string $reason, ?int $tradeId = null): void
    {
        if ($signalId <= 0) {
            return;
        }
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'UPDATE signals
                    SET decision        = :d,
                        decision_reason = :r,
                        decision_at     = :t,
                        trade_id        = COALESCE(:tid, trade_id)
                  WHERE id              = :id
                    AND decision        IS NULL'
            );
            $stmt->execute([
                ':d'   => $decision,
                ':r'   => $reason,
                ':t'   => self::nowIso(),
                ':tid' => $tradeId,
                ':id'  => $signalId,
            ]);
        } catch (\Throwable $e) {
            Logger::get()->warning('Decisions::record failed', [
                'signal_id' => $signalId,
                'decision'  => $decision,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retention: удалить сигналы старше N часов (по imported_at).
     * Возвращает число удалённых записей.
     */
    public static function cleanupOlderThanHours(int $hours = 24): int
    {
        if ($hours <= 0) {
            return 0;
        }
        try {
            $pdo = Database::pdo();
            $cutoffIso = gmdate('Y-m-d\TH:i:s\Z', time() - $hours * 3600);
            $stmt = $pdo->prepare('DELETE FROM signals WHERE imported_at < :c');
            $stmt->execute([':c' => $cutoffIso]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            Logger::get()->warning('Decisions::cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private static function nowIso(): string
    {
        $t = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\TH:i:s.', (int)$t) . $micro . 'Z';
    }
}
