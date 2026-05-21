<?php
declare(strict_types=1);

namespace BybitBot\Exchange;

use BybitBot\Bybit\Client;
use BybitBot\Core\BybitAccountsRepo;

/**
 * Фабрика адаптеров бирж. Выбирает реализацию по строке exchange.
 *
 * Допустимые значения: 'paper', 'testnet', 'live'.
 * Режим 'pause' не является exchange — он управляет поведением cron,
 * а не выбором адаптера.
 *
 * См. spec.md §13a (v0.5.0).
 */
final class AdapterFactory
{
    /**
     * v0.9.0: per-account кэш инстансов BybitAdapter.
     * Ключ — account_id, значение — готовый адаптер.
     * @var array<int,BybitAdapter>
     */
    private static $accountAdapters = [];

    /**
     * @param string $exchange 'paper'|'testnet'|'live'
     * @return ExchangeAdapter
     */
    public static function forExchange(string $exchange): ExchangeAdapter
    {
        if ($exchange === 'paper') {
            return PaperAdapter::default();
        }
        if ($exchange === 'testnet' || $exchange === 'live') {
            return new BybitAdapter($exchange);
        }
        // Fallback: неизвестный exchange → paper-режим с предупреждением
        return PaperAdapter::default();
    }

    /**
     * v0.9.0: адаптер для конкретного аккаунта из bybit_accounts. Кэшируется по id.
     *
     * @throws \RuntimeException если аккаунт не найден / нет ключей / network расхождение
     */
    public static function forAccount(int $accountId): BybitAdapter
    {
        if (isset(self::$accountAdapters[$accountId])) {
            return self::$accountAdapters[$accountId];
        }
        $acc = BybitAccountsRepo::find($accountId);
        if ($acc === null) {
            throw new \RuntimeException("AdapterFactory::forAccount: аккаунт #{$accountId} не найден");
        }
        $adapter = new BybitAdapter((string)$acc['network'], $accountId);
        self::$accountAdapters[$accountId] = $adapter;
        return $adapter;
    }

    /**
     * v0.9.0: сброс кэша (нужно после обновления ключей аккаунта,
     * чтобы в этом же запросе был создан новый Client с новыми ключами).
     */
    public static function resetAccount(int $accountId): void
    {
        unset(self::$accountAdapters[$accountId]);
    }

    /**
     * Создать адаптер для текущего mode из Config.
     * Если mode = 'pause' — возвращает PaperAdapter (pause влияет только на cron, не на адаптер).
     */
    public static function forCurrentMode(): ExchangeAdapter
    {
        $mode = (string)\BybitBot\Core\Config::get('mode', null, 'paper');
        if ($mode === 'pause') {
            $mode = 'paper'; // pause не меняет адаптер
        }
        return self::forExchange($mode);
    }
}
