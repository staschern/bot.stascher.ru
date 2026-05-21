<?php
declare(strict_types=1);

namespace BybitBot\Bybit;

use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;

/**
 * Кеш инструментов Bybit Linear (USDT-perpetual) и резолвер тикеров.
 *
 * Источник: GET /v5/market/instruments-info?category=linear
 * Обновляется в cron_daily (раз в сутки достаточно).
 *
 * Резолвер тикера (см. spec.md §3.1.4):
 *   1. symbol_aliases → ручной маппинг (приоритет)
 *   2. {symbol}USDT  → если есть в кеше и status=Trading
 *   3. префиксы 10, 100, 1000, 10000 + {symbol}USDT  → 1000PEPEUSDT и т.д.
 *   4. иначе → resolution_status='unresolved' (пользователь видит в UI и сопоставляет вручную)
 */
final class MarketInfo
{
    /** Префиксы для автопоиска фьючерс-вариантов токена. */
    private const PREFIXES = ['', '10', '100', '1000', '10000'];

    /**
     * Полностью обновить кеш инструментов из API.
     * Возвращает количество обновлённых записей.
     */
    public static function refresh(Client $client): int
    {
        $cursor = '';
        $count = 0;
        $pdo = Database::pdo();
        $now = self::nowIso();

        do {
            $query = ['category' => 'linear', 'limit' => 1000];
            if ($cursor !== '') $query['cursor'] = $cursor;

            $resp = $client->get('/v5/market/instruments-info', $query);
            if ($resp['category'] !== Errors::SUCCESS) {
                throw new \RuntimeException(
                    'Bybit instruments-info ошибка: ' .
                    ($resp['ret_msg'] ?? $resp['error'] ?? 'unknown') .
                    ' (category=' . $resp['category'] . ', http=' . $resp['http_code'] . ')'
                );
            }

            $list = $resp['result']['list'] ?? [];
            $cursor = (string)($resp['result']['nextPageCursor'] ?? '');

            foreach ($list as $inst) {
                self::upsertInstrument($pdo, $inst, $now);
                $count++;
            }
        } while ($cursor !== '' && $count < 10000); // safety cap

        return $count;
    }

    /**
     * Сопоставить source-символ (например 'PEPE') с реальным Bybit-символом ('1000PEPEUSDT').
     *
     * @return array{
     *   bybit_symbol:?string,
     *   resolution_status:string,   // 'resolved' | 'unresolved'
     *   matched_via:?string         // 'alias' | 'direct' | 'prefix:1000' | null
     * }
     */
    public static function resolve(string $sourceSymbol): array
    {
        $sourceSymbol = strtoupper(trim($sourceSymbol));
        if ($sourceSymbol === '') {
            return ['bybit_symbol' => null, 'resolution_status' => 'unresolved', 'matched_via' => null];
        }

        $pdo = Database::pdo();

        // 1. Алиас (приоритет). v0.7.5: фильтр по exchange='bybit' (сейчас биржа одна).
        $stmt = $pdo->prepare("SELECT bybit_symbol FROM symbol_aliases WHERE exchange = 'bybit' AND source_symbol = :s LIMIT 1");
        $stmt->execute([':s' => $sourceSymbol]);
        $aliased = $stmt->fetchColumn();
        if ($aliased !== false && $aliased !== null) {
            return [
                'bybit_symbol'      => (string)$aliased,
                'resolution_status' => 'resolved',
                'matched_via'       => 'alias',
            ];
        }

        // 2-3. Перебор префиксов
        $stmt = $pdo->prepare(
            "SELECT symbol FROM bybit_instruments
             WHERE symbol = :s AND status = 'Trading' AND quote_coin = 'USDT' LIMIT 1"
        );
        foreach (self::PREFIXES as $pref) {
            $candidate = $pref . $sourceSymbol . 'USDT';
            $stmt->execute([':s' => $candidate]);
            $found = $stmt->fetchColumn();
            if ($found !== false && $found !== null) {
                $matchedVia = $pref === '' ? 'direct' : ('prefix:' . $pref);
                // v0.7.5.1: авто-сохранение автоматического резолва.
                // Пишем в symbol_aliases только если ручного алиаса нет (выше проверка уже сделана).
                self::tryAutoSaveAlias($pdo, $sourceSymbol, (string)$found, $matchedVia);
                return [
                    'bybit_symbol'      => (string)$found,
                    'resolution_status' => 'resolved',
                    'matched_via'       => $matchedVia,
                ];
            }
        }

        return ['bybit_symbol' => null, 'resolution_status' => 'unresolved', 'matched_via' => null];
    }

    /**
     * v0.7.5.1: Пытаемся сохранить авто-резолв как алиас с created_by='auto'.
     * Использует INSERT OR IGNORE — ручные записи НЕ перезаписываем.
     * Ошибки проглатываем — это не критично для resolve().
     */
    private static function tryAutoSaveAlias(\PDO $pdo, string $sourceSymbol, string $bybitSymbol, string $matchedVia): void
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT OR IGNORE INTO symbol_aliases
                    (exchange, source_symbol, bybit_symbol, note, created_by, created_at, updated_at)
                 VALUES ('bybit', :s, :b, :n, 'auto', :t, :t)"
            );
            $stmt->execute([
                ':s' => $sourceSymbol,
                ':b' => $bybitSymbol,
                ':n' => 'auto: ' . $matchedVia,
                ':t' => self::nowIso(),
            ]);
        } catch (\Throwable $e) {
            // Не ломаем основной флоу резолва.
            Logger::get()->warning('tryAutoSaveAlias failed', [
                'source' => $sourceSymbol,
                'bybit'  => $bybitSymbol,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * v0.7.5.1: Бэкфилл алиасов из истории сигналов.
     * Берём все зарезолвенные сигналы, находим уникальные пары (short, full),
     * вставляем в symbol_aliases через INSERT OR IGNORE (не трогаем ручные).
     * @return array{inserted:int, skipped:int, total_pairs:int}
     */
    public static function backfillFromHistory(): array
    {
        $pdo = Database::pdo();

        // Уникальные пары из signals где bybit_symbol реально проставлен.
        $rows = $pdo->query(
            "SELECT DISTINCT UPPER(symbol) AS src, UPPER(bybit_symbol) AS dst
             FROM signals
             WHERE resolution_status = 'resolved'
               AND bybit_symbol IS NOT NULL
               AND bybit_symbol != ''
               AND symbol IS NOT NULL
               AND symbol != ''"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $now = self::nowIso();
        $stmt = $pdo->prepare(
            "INSERT OR IGNORE INTO symbol_aliases
                (exchange, source_symbol, bybit_symbol, note, created_by, created_at, updated_at)
             VALUES ('bybit', :s, :b, 'backfill from signals history', 'backfill', :t, :t)"
        );

        $inserted = 0;
        foreach ($rows as $r) {
            $stmt->execute([':s' => (string)$r['src'], ':b' => (string)$r['dst'], ':t' => $now]);
            if ($stmt->rowCount() > 0) {
                $inserted++;
            }
        }

        return [
            'inserted'    => $inserted,
            'skipped'     => count($rows) - $inserted,
            'total_pairs' => count($rows),
        ];
    }

    /**
     * Добавить ручной алиас (через UI/CLI).
     * Возвращает true если добавлено/обновлено.
     */
    public static function addAlias(string $sourceSymbol, string $bybitSymbol, ?string $note, ?string $createdBy): bool
    {
        $sourceSymbol = strtoupper(trim($sourceSymbol));
        $bybitSymbol  = strtoupper(trim($bybitSymbol));
        if ($sourceSymbol === '' || $bybitSymbol === '') {
            throw new \InvalidArgumentException('source и bybit символы не могут быть пустыми');
        }

        // Проверим, что bybit-символ существует в кеше
        $stmt = Database::pdo()->prepare(
            "SELECT 1 FROM bybit_instruments WHERE symbol = :s AND status = 'Trading' LIMIT 1"
        );
        $stmt->execute([':s' => $bybitSymbol]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException(
                "Bybit-символ '{$bybitSymbol}' не найден среди торгуемых инструментов. " .
                "Обновите кеш: php bin/cli.php bybit:refresh-instruments"
            );
        }

        // v0.7.5: поддержка колонки exchange. Пока всегда 'bybit' — при подключении других бирж параметризуется.
        $stmt = Database::pdo()->prepare(
            "INSERT INTO symbol_aliases (exchange, source_symbol, bybit_symbol, note, created_by, created_at, updated_at)
             VALUES ('bybit', :s, :b, :n, :c, :t, :t)
             ON CONFLICT(exchange, source_symbol) DO UPDATE SET
                bybit_symbol = :b,
                note         = :n,
                created_by   = :c,
                updated_at   = :t"
        );
        $stmt->execute([
            ':s' => $sourceSymbol,
            ':b' => $bybitSymbol,
            ':n' => $note,
            ':c' => $createdBy,
            ':t' => self::nowIso(),
        ]);

        EventRecorder::event(EventRecorder::INFO, 'symbol_alias_set', $sourceSymbol, [
            'bybit_symbol' => $bybitSymbol,
            'note'         => $note,
            'created_by'   => $createdBy,
        ]);

        return true;
    }

    /**
     * v0.7.5: получить все алиасы для биржи (по умолчанию 'bybit').
     * @return array<int,array{exchange:string,source_symbol:string,bybit_symbol:string,note:?string,created_by:?string,created_at:string,updated_at:?string}>
     */
    public static function listAliases(string $exchange = 'bybit'): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT exchange, source_symbol, bybit_symbol, note, created_by, created_at, updated_at
             FROM symbol_aliases
             WHERE exchange = :ex
             ORDER BY source_symbol ASC"
        );
        $stmt->execute([':ex' => $exchange]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * v0.7.5: удалить алиас. Возвращает true если реально удалён.
     */
    public static function deleteAlias(string $sourceSymbol, string $exchange = 'bybit'): bool
    {
        $sourceSymbol = strtoupper(trim($sourceSymbol));
        if ($sourceSymbol === '') return false;

        $stmt = Database::pdo()->prepare(
            "DELETE FROM symbol_aliases WHERE exchange = :ex AND source_symbol = :s"
        );
        $stmt->execute([':ex' => $exchange, ':s' => $sourceSymbol]);
        $deleted = $stmt->rowCount() > 0;

        if ($deleted) {
            EventRecorder::event(EventRecorder::INFO, 'symbol_alias_deleted', $sourceSymbol, [
                'exchange' => $exchange,
            ]);
        }

        return $deleted;
    }

    /** Сколько инструментов в кеше? */
    public static function instrumentsCount(): int
    {
        try {
            return (int)Database::pdo()->query('SELECT COUNT(*) FROM bybit_instruments')->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Перерезолвить все unresolved сигналы (после refresh инструментов или новых алиасов).
     * Возвращает [resolved, still_unresolved].
     */
    public static function reresolveSignals(): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->query(
            "SELECT id, symbol FROM signals WHERE resolution_status = 'unresolved'"
        )->fetchAll();

        $resolved = 0;
        $now = self::nowIso();
        $upd = $pdo->prepare(
            "UPDATE signals SET bybit_symbol = :b, resolution_status = :r, resolved_at = :t WHERE id = :id"
        );

        foreach ($rows as $r) {
            $res = self::resolve((string)$r['symbol']);
            if ($res['resolution_status'] === 'resolved') {
                $upd->execute([
                    ':b'  => $res['bybit_symbol'],
                    ':r'  => 'resolved',
                    ':t'  => $now,
                    ':id' => (int)$r['id'],
                ]);
                $resolved++;
            }
        }

        return ['resolved' => $resolved, 'still_unresolved' => count($rows) - $resolved];
    }

    /**
     * @param array $inst  один объект из result.list
     */
    private static function upsertInstrument(\PDO $pdo, array $inst, string $now): void
    {
        $symbol     = (string)($inst['symbol'] ?? '');
        if ($symbol === '') return;

        $baseCoin   = (string)($inst['baseCoin']   ?? '');
        $quoteCoin  = (string)($inst['quoteCoin']  ?? '');
        $contract   = (string)($inst['contractType'] ?? '');
        $status     = (string)($inst['status']     ?? '');
        $tickSize   = isset($inst['priceFilter']['tickSize'])
            ? (float)$inst['priceFilter']['tickSize'] : null;
        $qtyStep    = isset($inst['lotSizeFilter']['qtyStep'])
            ? (float)$inst['lotSizeFilter']['qtyStep'] : null;
        $minQty     = isset($inst['lotSizeFilter']['minOrderQty'])
            ? (float)$inst['lotSizeFilter']['minOrderQty'] : null;
        $maxLev     = isset($inst['leverageFilter']['maxLeverage'])
            ? (float)$inst['leverageFilter']['maxLeverage'] : null;

        $stmt = $pdo->prepare(
            'INSERT INTO bybit_instruments
                (symbol, base_coin, quote_coin, contract_type, status,
                 tick_size, qty_step, min_order_qty, max_leverage, raw_json, updated_at)
             VALUES (:s, :b, :q, :ct, :st, :ts, :qs, :mq, :ml, :raw, :u)
             ON CONFLICT(symbol) DO UPDATE SET
                base_coin     = :b,
                quote_coin    = :q,
                contract_type = :ct,
                status        = :st,
                tick_size     = :ts,
                qty_step      = :qs,
                min_order_qty = :mq,
                max_leverage  = :ml,
                raw_json      = :raw,
                updated_at    = :u'
        );
        $stmt->execute([
            ':s'   => $symbol,
            ':b'   => $baseCoin,
            ':q'   => $quoteCoin,
            ':ct'  => $contract,
            ':st'  => $status,
            ':ts'  => $tickSize,
            ':qs'  => $qtyStep,
            ':mq'  => $minQty,
            ':ml'  => $maxLev,
            ':raw' => json_encode($inst, JSON_UNESCAPED_UNICODE),
            ':u'   => $now,
        ]);
    }

    private static function nowIso(): string
    {
        $t = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\TH:i:s.', (int)$t) . $micro . 'Z';
    }
}
