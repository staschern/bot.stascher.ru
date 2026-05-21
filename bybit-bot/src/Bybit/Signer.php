<?php
declare(strict_types=1);

namespace BybitBot\Bybit;

/**
 * Подпись запросов к Bybit V5 API.
 *
 * Формат подписи (Bybit V5):
 *   pre_sign = timestamp + apiKey + recvWindow + payload
 *   payload  = (для GET) — query string без '?', отсортированных НЕТ требований,
 *              использовать ту же строку, что и в URL
 *              (для POST) — JSON-строка тела запроса (как отправляется)
 *   signature = HMAC_SHA256(pre_sign, apiSecret) в HEX
 *
 * См. https://bybit-exchange.github.io/docs/v5/guide#authentication
 */
final class Signer
{
    /** @var string */
    private $apiKey;

    /** @var string */
    private $apiSecret;

    /** @var int */
    private $recvWindow;

    public function __construct(string $apiKey, string $apiSecret, int $recvWindow = 5000)
    {
        $this->apiKey     = $apiKey;
        $this->apiSecret  = $apiSecret;
        $this->recvWindow = $recvWindow;
    }

    /**
     * Возвращает заголовки для авторизованного запроса.
     *
     * @param string $payload — query string (GET) или JSON body (POST), как уйдёт по сети
     * @return array<string,string>
     */
    public function headers(string $payload): array
    {
        $timestamp = $this->timestampMs();
        $preSign   = $timestamp . $this->apiKey . $this->recvWindow . $payload;
        $signature = hash_hmac('sha256', $preSign, $this->apiSecret);

        return [
            'X-BAPI-API-KEY'     => $this->apiKey,
            'X-BAPI-TIMESTAMP'   => $timestamp,
            'X-BAPI-RECV-WINDOW' => (string)$this->recvWindow,
            'X-BAPI-SIGN'        => $signature,
            'X-BAPI-SIGN-TYPE'   => '2',
        ];
    }

    /** Текущая метка времени в миллисекундах (как требует Bybit). */
    private function timestampMs(): string
    {
        return (string)(int)(microtime(true) * 1000);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }
}
