<?php
declare(strict_types=1);

namespace BybitBot\Exchange;

/**
 * Контракт адаптера биржи. См. spec.md §11.
 *
 * Реализации:
 *  - BybitAdapter        (mainnet, REST V5, HMAC) — Этап 2
 *  - BybitTestnetAdapter (тот же класс с другой baseUrl) — Этап 2
 *  - PaperAdapter        (read-only mainnet + симуляция) — Этап 2
 */
interface ExchangeAdapter
{
    // ── справочники и состояние ─────────────────────────────
    /** @return array{tickSize:float, qtyStep:float, qtyMin:float, leverageFilter:array} */
    public function getInstrumentInfo(string $symbol): array;

    /** @return array<int, array{open:float,high:float,low:float,close:float,start:int}> */
    public function getKline(string $symbol, string $interval, int $limit): array;

    /** @return array{totalEquity:float, availableBalance:float} */
    public function getWalletBalance(): array;

    /** @return array<int, array> */
    public function getPositions(?string $symbol = null): array;

    /** @return array<int, array> */
    public function getOpenOrders(?string $symbol = null): array;

    /** @return array<int, array> */
    public function getExecutions(string $orderLinkIdOrSymbol, int $sinceUnixMs): array;

    /** @return array{rate:float, nextFundingTime:int} */
    public function getFundingRate(string $symbol): array;

    /** @return array<int, array> */
    public function getFundingHistory(string $symbol, int $sinceUnixMs): array;

    /** @return int Unix-time биржи (мс). */
    public function getServerTime(): int;

    // ── торговые операции ────────────────────────────────────
    /** @return string orderId (или внутренний ID симулятора) */
    public function placeConditional(array $params): string;

    public function cancelOrder(string $orderIdOrLink): bool;

    public function amendOrder(string $orderId, array $fields): bool;

    /** SL/TP/trailing/active price на открытой позиции. */
    public function setTradingStop(string $symbol, string $side, array $fields): bool;

    public function setLeverage(string $symbol, int $value): bool;

    public function switchMarginMode(string $crossOrIsolated): bool;

    /**
     * Reduce-only лимит-ордер для частичного TP в S2 (60% объёма по введённому TP).
     * См. spec.md §11 и §18.2.5.
     */
    public function placeReduceOnlyLimit(
        string $symbol,
        string $side,
        float $qty,
        float $price,
        string $orderLinkId
    ): string;
}
