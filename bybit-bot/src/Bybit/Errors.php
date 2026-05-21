<?php
declare(strict_types=1);

namespace BybitBot\Bybit;

/**
 * Классификация ошибок Bybit API V5.
 *
 * См. https://bybit-exchange.github.io/docs/v5/error
 *
 * Категории:
 *  - SUCCESS        — retCode = 0
 *  - RATE_LIMIT     — превышен лимит, нужно подождать (429 / retCode 10006/10018)
 *  - TRANSIENT      — временная ошибка, имеет смысл retry (5xx, timeout, 10002 timestamp)
 *  - AUTH           — проблема ключа/подписи (10003, 10004, 10005, 33004)
 *  - PERMANENT      — бизнес-ошибка, retry бесполезен (валидация параметров, инструмент закрыт и т.п.)
 *  - UNKNOWN        — не классифицировано
 */
final class Errors
{
    public const SUCCESS    = 'success';
    public const RATE_LIMIT = 'rate_limit';
    public const TRANSIENT  = 'transient';
    public const AUTH       = 'auth';
    public const PERMANENT  = 'permanent';
    public const UNKNOWN    = 'unknown';

    /**
     * Классифицировать ответ Bybit.
     *
     * @param int      $httpCode    HTTP status (0 если транспортная ошибка)
     * @param int|null $retCode     ret_code из тела ответа (null если тело не JSON)
     * @return string  одна из констант выше
     */
    public static function classify(int $httpCode, ?int $retCode): string
    {
        // Транспортный уровень
        if ($httpCode === 0)         return self::TRANSIENT;
        if ($httpCode === 429)       return self::RATE_LIMIT;
        if ($httpCode >= 500)        return self::TRANSIENT;

        // Bybit retCode уровень
        if ($retCode === null) {
            // 4xx без понятного retCode → permanent
            return self::PERMANENT;
        }
        if ($retCode === 0) return self::SUCCESS;

        // Rate limit
        if (in_array($retCode, [10006, 10018, 10019], true)) {
            return self::RATE_LIMIT;
        }

        // Auth / signature
        // 10003 — invalid api_key
        // 10004 — sign for this request is not match
        // 10005 — permission denied
        // 33004 — your api_key has expired
        if (in_array($retCode, [10003, 10004, 10005, 33004], true)) {
            return self::AUTH;
        }

        // Timestamp drift / system busy → transient
        // 10002 — request not authorized due to timestamp issue
        // 10016 — server error
        if (in_array($retCode, [10002, 10016], true)) {
            return self::TRANSIENT;
        }

        return self::PERMANENT;
    }

    /** Базовый backoff в секундах для rate-limit и transient ошибок. */
    public static function backoffSeconds(string $category, int $attempt): float
    {
        if ($category === self::RATE_LIMIT) {
            // Bybit рекомендует подождать 1-5 секунд при rate-limit
            return min(5.0, 1.0 * pow(2, $attempt - 1));
        }
        if ($category === self::TRANSIENT) {
            // Экспоненциальный backoff: 1, 2, 4 сек
            return min(8.0, 1.0 * pow(2, $attempt - 1));
        }
        return 0.0;
    }
}
