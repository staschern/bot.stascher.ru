<?php
declare(strict_types=1);

namespace BybitBot\Signals;

use BybitBot\Bybit\MarketInfo;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Signals\Decisions;

/**
 * Импорт сигналов из signalsHourly.json в таблицу `signals`.
 *
 * Стратегия импорта:
 *  - Читаем файл (paths.signals_source)
 *  - Берём MAX(saved_at_utc) из БД → $lastImported
 *  - Импортируем только сигналы с saved_at_utc > $lastImported (см. spec.md §3.1)
 *    (если БД пустая — импортируем всё)
 *  - INSERT OR IGNORE по уникальному ключу (date, time, symbol, side) защищает от дублей
 *  - Сразу при импорте резолвим bybit_symbol через MarketInfo
 *
 * Источник: см. /var/www/.../finManager/shared/signalsHourly.json (формат — Validator).
 *
 * Часовой пояс источника: Europe/Moscow (UTC+3) — поле savedAt.
 */
final class Importer
{
    /** Часовой пояс источника signalsHourly.json. */
    private const SOURCE_TZ = 'Europe/Moscow';

    /**
     * @return array{
     *   imported:int, skipped:int, failed:int, total_in_file:int,
     *   resolved:int, unresolved:int,
     *   source_mtime:?string, source_mtime_age_sec:?int,
     *   freshness_ok:bool
     * }
     */
    public static function run(): array
    {
        $path = (string)Config::bootstrap('paths.signals_source');
        if ($path === '' || !is_file($path)) {
            EventRecorder::event(EventRecorder::ERROR, 'signals_source_missing', null, ['path' => $path]);
            throw new \RuntimeException("Файл сигналов не найден: {$path}");
        }

        $mtime = (int)filemtime($path);
        $mtimeIso = gmdate('Y-m-d\TH:i:s\Z', $mtime);
        $ageSec = time() - $mtime;
        $freshnessMinutes = (int)Config::get('signals_freshness_minutes', null, 90);
        $freshnessOk = $ageSec <= ($freshnessMinutes * 60);
        if (!$freshnessOk) {
            EventRecorder::event(EventRecorder::WARN, 'signals_source_stale', null, [
                'path'       => $path,
                'mtime'      => $mtimeIso,
                'age_sec'    => $ageSec,
                'limit_min'  => $freshnessMinutes,
            ]);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Не удалось прочитать файл: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['signals']) || !is_array($data['signals'])) {
            EventRecorder::event(EventRecorder::ERROR, 'signals_source_invalid_json', null, ['path' => $path]);
            throw new \RuntimeException("Невалидный JSON или отсутствует ключ 'signals' в {$path}");
        }
        $signals = $data['signals'];
        $totalInFile = count($signals);

        $pdo = Database::pdo();

        // v0.7.7: retention — удалить сигналы старше 24 часов перед импортом.
        $purged = Decisions::cleanupOlderThanHours(24);
        if ($purged > 0) {
            EventRecorder::event(EventRecorder::INFO, 'signals_retention_cleanup', null, ['purged' => $purged]);
        }

        // last_imported по saved_at_utc — пограничный момент (не включительно)
        $lastImportedUtc = (string)$pdo->query(
            'SELECT COALESCE(MAX(saved_at_utc), \'\') FROM signals'
        )->fetchColumn();

        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $resolved = 0;
        $unresolved = 0;

        $insertStmt = $pdo->prepare(
            'INSERT OR IGNORE INTO signals
                (date, time, saved_at, saved_at_utc, symbol, side, target,
                 signal_type, potential, rsi, w7, w14, w30, w_all,
                 bybit_symbol, resolution_status, resolved_at, imported_at)
             VALUES
                (:d, :t, :sa, :sau, :sym, :sd, :tg,
                 :st, :pot, :rsi, :w7, :w14, :w30, :wa,
                 :bs, :rs, :rt, :im)'
        );

        $now = self::nowIso();

        foreach ($signals as $idx => $sig) {
            try {
                $valid = Validator::validate($sig, $idx);
                if (!$valid['ok']) {
                    EventRecorder::event(EventRecorder::WARN, 'signal_validation_failed', null, [
                        'index'  => $idx,
                        'errors' => $valid['errors'],
                        'sample' => $sig,
                    ]);
                    $failed++;
                    continue;
                }
                $sig = $valid['signal']; // нормализованные значения

                // Конвертируем savedAt (MSK) → UTC ISO8601
                $savedAtUtc = self::msktoUtcIso($sig['savedAt']);

                // Skip если уже импортировано более старое (b — only newer)
                if ($lastImportedUtc !== '' && strcmp($savedAtUtc, $lastImportedUtc) <= 0) {
                    $skipped++;
                    continue;
                }

                // Резолвим символ
                $resolved_ = MarketInfo::resolve((string)$sig['symbol']);
                if ($resolved_['resolution_status'] === 'resolved') {
                    $resolved++;
                } else {
                    $unresolved++;
                }

                $insertStmt->execute([
                    ':d'   => $sig['date'],
                    ':t'   => $sig['time'],
                    ':sa'  => $sig['savedAt'],
                    ':sau' => $savedAtUtc,
                    ':sym' => $sig['symbol'],
                    ':sd'  => $sig['side'],
                    ':tg'  => $sig['target'],
                    ':st'  => $sig['strategy'], // поле в источнике называется strategy, в БД пишется как signal_type
                    ':pot' => $sig['potential'] ? 1 : 0,
                    ':rsi' => $sig['rsi'],
                    ':w7'  => $sig['weights']['w7']   ?? null,
                    ':w14' => $sig['weights']['w14']  ?? null,
                    ':w30' => $sig['weights']['w30']  ?? null,
                    ':wa'  => $sig['weights']['wAll'] ?? null,
                    ':bs'  => $resolved_['bybit_symbol'],
                    ':rs'  => $resolved_['resolution_status'],
                    ':rt'  => $resolved_['resolution_status'] === 'resolved' ? $now : null,
                    ':im'  => $now,
                ]);

                if ($insertStmt->rowCount() === 0) {
                    // Уникальный индекс отработал → дубликат, skip
                    $skipped++;
                } else {
                    $imported++;
                    // v0.7.7: если символ не сопоставлен — сразу фиксируем решение.
                    if ($resolved_['resolution_status'] !== 'resolved') {
                        $newId = (int)$pdo->lastInsertId();
                        Decisions::record(
                            $newId,
                            Decisions::REJECTED_UNRESOLVED,
                            'символ не сопоставлен с фьючерсом Bybit'
                        );
                    }
                }
            } catch (\Throwable $e) {
                EventRecorder::event(EventRecorder::ERROR, 'signal_import_failed', null, [
                    'index' => $idx,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        EventRecorder::event(EventRecorder::INFO, 'signals_import_summary', null, [
            'total_in_file' => $totalInFile,
            'imported'      => $imported,
            'skipped'       => $skipped,
            'failed'        => $failed,
            'resolved'      => $resolved,
            'unresolved'    => $unresolved,
            'source_mtime'  => $mtimeIso,
            'age_sec'       => $ageSec,
            'freshness_ok'  => $freshnessOk,
        ]);

        return [
            'imported'             => $imported,
            'skipped'              => $skipped,
            'failed'               => $failed,
            'total_in_file'        => $totalInFile,
            'resolved'             => $resolved,
            'unresolved'           => $unresolved,
            'source_mtime'         => $mtimeIso,
            'source_mtime_age_sec' => $ageSec,
            'freshness_ok'         => $freshnessOk,
        ];
    }

    /**
     * 'YYYY-MM-DD HH:MM:SS' (MSK) → 'YYYY-MM-DDTHH:MM:SSZ' (UTC)
     */
    private static function msktoUtcIso(string $savedAt): string
    {
        $tz = new \DateTimeZone(self::SOURCE_TZ);
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $savedAt, $tz);
        if ($dt === false) {
            throw new \RuntimeException("Невалидный savedAt: {$savedAt}");
        }
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    }

    private static function nowIso(): string
    {
        $t = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\TH:i:s.', (int)$t) . $micro . 'Z';
    }
}
