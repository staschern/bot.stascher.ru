<?php
declare(strict_types=1);

namespace BybitBot\Bybit;

use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

/**
 * HTTP-клиент Bybit V5 API.
 *
 * Возможности:
 *  - GET / POST к публичным и приватным endpoint'ам
 *  - HMAC SHA256 подпись через Signer (только для приватных)
 *  - Retry для transient/rate-limit ошибок (до 3 попыток с экспоненциальным backoff)
 *  - Логирование каждого запроса в `api_calls` (см. spec.md §12)
 *
 * Использование:
 *   $client = Client::default(); // создаёт инстанс по конфигу
 *   $r = $client->get('/v5/market/time');                  // публичный
 *   $r = $client->getSigned('/v5/account/wallet-balance'); // приватный
 *
 * Возврат: ассоциативный массив с ключами 'category', 'http_code', 'ret_code',
 *          'ret_msg', 'result', 'duration_ms', 'retries'.
 *          В случае фатальной ошибки 'category' = transient/auth/permanent.
 */
final class Client
{
    private const MAX_RETRIES = 3;

    /** @var HttpClient */
    private $http;

    /** @var Signer|null */
    private $signer;

    /**
     * Базовый URL для ПУБЛИЧНЫХ эндпоинтов (kline, tickers, instruments, server-time, funding-rate).
     * Всегда mainnet — независимо от mode — чтобы paper/testnet считали стратегию на реальных данных.
     * @var string
     */
    private $baseUrlPublic;

    /**
     * Базовый URL для ПОДПИСАННЫХ эндпоинтов (orders, positions, balance, set-leverage).
     * live → mainnet, paper/testnet → testnet.
     * @var string
     */
    private $baseUrlPrivate;

    /** @var string  'mainnet' | 'testnet' (только для signed эндпоинтов) */
    private $network;

    /** @var bool */
    private $debugLog;

    public function __construct(string $baseUrlPublic, string $baseUrlPrivate, ?Signer $signer, string $network, bool $debugLog = false)
    {
        $this->baseUrlPublic  = rtrim($baseUrlPublic, '/');
        $this->baseUrlPrivate = rtrim($baseUrlPrivate, '/');
        $this->signer         = $signer;
        $this->network        = $network;
        $this->debugLog       = $debugLog;

        // Без base_uri — конкретный URL подставляем в каждый запрос (см. request()).
        $this->http = new HttpClient([
            'timeout'         => 15.0,
            'connect_timeout' => 5.0,
            'http_errors'     => false,
        ]);
    }

    /**
     * Создать клиент по конфигу окружения.
     * Использует тот network, что задан в settings.mode (testnet/live → mainnet).
     * Для paper-режима подпись не требуется (signer = null).
     */
    public static function default(): self
    {
        $mode = (string)Config::get('mode', null, 'paper');

        $mainnetUrl = (string)Config::bootstrap('bybit.base_url_mainnet');
        $testnetUrl = (string)Config::bootstrap('bybit.base_url_testnet');

        // Public эндпоинты (kline/tickers/instruments) — ВСЕГДА mainnet,
        // чтобы стратегия в paper и testnet режимах считала параметры на реальных рыночных данных.
        $baseUrlPublic = $mainnetUrl;

        // Private (signed) эндпоинты — по mode.
        if ($mode === 'live') {
            $network        = 'mainnet';
            $baseUrlPrivate = $mainnetUrl;
            $apiKey         = (string)($_ENV['BYBIT_API_KEY_MAINNET'] ?? '');
            $apiSecret      = (string)($_ENV['BYBIT_API_SECRET_MAINNET'] ?? '');
        } else {
            $network        = 'testnet';
            $baseUrlPrivate = $testnetUrl;
            $apiKey         = (string)($_ENV['BYBIT_API_KEY_TESTNET'] ?? '');
            $apiSecret      = (string)($_ENV['BYBIT_API_SECRET_TESTNET'] ?? '');
        }

        $recvWindow = (int)Config::bootstrap('bybit.recv_window', 5000);
        $debugLog   = filter_var($_ENV['BYBIT_DEBUG_LOG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        $signer = ($apiKey !== '' && $apiSecret !== '')
            ? new Signer($apiKey, $apiSecret, $recvWindow)
            : null;

        return new self($baseUrlPublic, $baseUrlPrivate, $signer, $network, $debugLog);
    }

    /**
     * GET-запрос к публичному endpoint'у (без подписи).
     *
     * @param string               $endpoint  '/v5/market/time'
     * @param array<string,string|int|float> $query
     * @return array
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, $query, null, false);
    }

    /**
     * GET-запрос с подписью (для приватных endpoint'ов).
     *
     * @param array<string,string|int|float> $query
     */
    public function getSigned(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, $query, null, true);
    }

    /**
     * POST-запрос с подписью.
     *
     * @param array<string,mixed> $body
     */
    public function postSigned(string $endpoint, array $body): array
    {
        return $this->request('POST', $endpoint, [], $body, true);
    }

    /**
     * Низкоуровневый исполнитель запроса с retry-логикой.
     *
     * @param array<string,mixed>|null $body
     * @return array{
     *   category:string, http_code:int, ret_code:int|null, ret_msg:?string,
     *   result:array|null, duration_ms:int, retries:int, error:?string
     * }
     */
    private function request(
        string $method,
        string $endpoint,
        array $query,
        ?array $body,
        bool $signed
    ): array {
        if ($signed && $this->signer === null) {
            return [
                'category'    => Errors::AUTH,
                'http_code'   => 0,
                'ret_code'    => null,
                'ret_msg'     => 'Bybit API ключи не настроены (BYBIT_API_KEY_* в .env)',
                'result'      => null,
                'duration_ms' => 0,
                'retries'     => 0,
                'error'       => 'no_credentials',
            ];
        }

        $attempt = 0;
        $totalStart = microtime(true);
        $lastResult = null;

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            $start = microtime(true);

            $queryStr = $query === [] ? '' : http_build_query($query);
            $bodyStr  = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
            $payload  = $method === 'GET' ? $queryStr : (string)$bodyStr;

            $headers = ['Content-Type' => 'application/json'];
            if ($signed && $this->signer !== null) {
                $headers = array_merge($headers, $this->signer->headers($payload));
            }

            // Выбираем baseUrl по типу запроса: signed → private (mode-зависимый),
            // public → всегда mainnet.
            $baseUrl = $signed ? $this->baseUrlPrivate : $this->baseUrlPublic;
            $url     = $baseUrl . $endpoint . ($queryStr !== '' ? '?' . $queryStr : '');

            try {
                $options = ['headers' => $headers];
                if ($body !== null) {
                    $options['body'] = $bodyStr;
                }
                $resp     = $this->http->request($method, $url, $options);
                $httpCode = $resp->getStatusCode();
                $rawBody  = (string)$resp->getBody();
                $json     = json_decode($rawBody, true);

                $retCode = is_array($json) && isset($json['retCode']) ? (int)$json['retCode'] : null;
                $retMsg  = is_array($json) && isset($json['retMsg'])  ? (string)$json['retMsg'] : null;
                $result  = is_array($json) && isset($json['result'])  && is_array($json['result'])
                    ? $json['result'] : null;

                $category = Errors::classify($httpCode, $retCode);
                $durationMs = (int)round((microtime(true) - $start) * 1000);

                $lastResult = [
                    'category'    => $category,
                    'http_code'   => $httpCode,
                    'ret_code'    => $retCode,
                    'ret_msg'     => $retMsg,
                    'result'      => $result,
                    'duration_ms' => $durationMs,
                    'retries'     => $attempt - 1,
                    'error'       => null,
                ];

                $this->logCall($endpoint, $method, $body, $httpCode, $rawBody, $durationMs);

                // Успех → выходим
                if ($category === Errors::SUCCESS) {
                    return $lastResult;
                }

                // Не-ретраябельные → выходим
                if ($category !== Errors::TRANSIENT && $category !== Errors::RATE_LIMIT) {
                    return $lastResult;
                }

                // Ждём перед retry
                $sleep = Errors::backoffSeconds($category, $attempt);
                if ($sleep > 0 && $attempt < self::MAX_RETRIES) {
                    usleep((int)($sleep * 1_000_000));
                }
            } catch (ConnectException $e) {
                $durationMs = (int)round((microtime(true) - $start) * 1000);
                $lastResult = [
                    'category'    => Errors::TRANSIENT,
                    'http_code'   => 0,
                    'ret_code'    => null,
                    'ret_msg'     => 'connection_error',
                    'result'      => null,
                    'duration_ms' => $durationMs,
                    'retries'     => $attempt - 1,
                    'error'       => $e->getMessage(),
                ];
                $this->logCall($endpoint, $method, $body, 0, null, $durationMs, $e->getMessage());
                if ($attempt < self::MAX_RETRIES) {
                    usleep((int)(Errors::backoffSeconds(Errors::TRANSIENT, $attempt) * 1_000_000));
                }
            } catch (RequestException $e) {
                $durationMs = (int)round((microtime(true) - $start) * 1000);
                $httpCode   = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                $lastResult = [
                    'category'    => Errors::classify($httpCode, null),
                    'http_code'   => $httpCode,
                    'ret_code'    => null,
                    'ret_msg'     => 'request_exception',
                    'result'      => null,
                    'duration_ms' => $durationMs,
                    'retries'     => $attempt - 1,
                    'error'       => $e->getMessage(),
                ];
                $this->logCall($endpoint, $method, $body, $httpCode, null, $durationMs, $e->getMessage());
                if ($lastResult['category'] !== Errors::TRANSIENT && $lastResult['category'] !== Errors::RATE_LIMIT) {
                    return $lastResult;
                }
                if ($attempt < self::MAX_RETRIES) {
                    usleep((int)(Errors::backoffSeconds($lastResult['category'], $attempt) * 1_000_000));
                }
            }
        }

        // Все попытки исчерпаны
        return $lastResult ?? [
            'category'    => Errors::UNKNOWN,
            'http_code'   => 0,
            'ret_code'    => null,
            'ret_msg'     => 'no_attempts_made',
            'result'      => null,
            'duration_ms' => (int)round((microtime(true) - $totalStart) * 1000),
            'retries'     => 0,
            'error'       => 'unknown',
        ];
    }

    /**
     * Записать запрос в `api_calls` (см. spec.md §12).
     * Тело запроса сохраняем только при BYBIT_DEBUG_LOG=true (PII).
     *
     * @param array|null $body
     */
    private function logCall(
        string $endpoint,
        string $method,
        ?array $body,
        int $httpCode,
        ?string $responseBody,
        int $durationMs,
        ?string $errorMessage = null
    ): void {
        try {
            $reqJson = null;
            $resJson = null;
            if ($this->debugLog) {
                if ($body !== null) {
                    $reqJson = json_encode($body, JSON_UNESCAPED_UNICODE);
                }
                if ($responseBody !== null) {
                    // Обрезаем до 4кб, чтобы не раздувать БД
                    $resJson = substr($responseBody, 0, 4096);
                }
            } elseif ($errorMessage !== null) {
                $resJson = json_encode(['error' => $errorMessage], JSON_UNESCAPED_UNICODE);
            } elseif ($responseBody !== null && $httpCode !== 200) {
                // Для не-200 всегда сохраняем тело (для расследований)
                $resJson = substr($responseBody, 0, 2048);
            }

            $stmt = Database::pdo()->prepare(
                'INSERT INTO api_calls (ts, endpoint, method, request_json, response_json, http_status, duration_ms)
                 VALUES (:ts, :ep, :m, :rq, :rs, :st, :d)'
            );
            $stmt->execute([
                ':ts' => self::nowIso(),
                ':ep' => $endpoint,
                ':m'  => $method,
                ':rq' => $reqJson,
                ':rs' => $resJson,
                ':st' => $httpCode,
                ':d'  => $durationMs,
            ]);
        } catch (\Throwable $e) {
            // Логирование не должно ломать основной flow
            EventRecorder::event(EventRecorder::WARN, 'bybit_log_call_failed', null, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function isAuthorized(): bool
    {
        return $this->signer !== null;
    }

    public function getNetwork(): string
    {
        return $this->network;
    }

    /**
     * @deprecated Используйте getBaseUrlPublic() или getBaseUrlPrivate().
     * Возвращает private baseUrl (для обратной совместимости).
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrlPrivate;
    }

    public function getBaseUrlPublic(): string
    {
        return $this->baseUrlPublic;
    }

    public function getBaseUrlPrivate(): string
    {
        return $this->baseUrlPrivate;
    }

    private static function nowIso(): string
    {
        $t = microtime(true);
        $micro = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return gmdate('Y-m-d\TH:i:s.', (int)$t) . $micro . 'Z';
    }

    // ── Методы для Stage 2 item 3 ────────────────────────────

    /**
     * Получить kline (свечи) для символа.
     * Публичный endpoint, подпись не нужна.
     * Возвращает массив свечей в формате [[start, open, high, low, close, volume], ...]
     * отсортированных от новых к старым.
     *
     * @param string $symbol   Bybit symbol (e.g. 'TONUSDT')
     * @param string $interval Интервал: '1','3','5','15','30','60','120','240','360','720','D','M','W'
     * @param int    $limit    Кол-во свечей (max 200)
     * @return array
     */
    public function getKline(string $symbol, string $interval = '60', int $limit = 2): array
    {
        return $this->get('/v5/market/kline', [
            'category' => 'linear',
            'symbol'   => $symbol,
            'interval' => $interval,
            'limit'    => $limit,
        ]);
    }

    /**
     * Получить тикеры (24h статистика + текущая цена) для символа.
     * Публичный endpoint.
     *
     * @param string $symbol
     * @return array
     */
    public function getTickers24h(string $symbol): array
    {
        return $this->get('/v5/market/tickers', [
            'category' => 'linear',
            'symbol'   => $symbol,
        ]);
    }

    /**
     * Получить баланс кошелька UTA.
     *
     * @param string $accountType 'UNIFIED' для UTA
     * @return array
     */
    public function getWalletBalance(string $accountType = 'UNIFIED'): array
    {
        return $this->getSigned('/v5/account/wallet-balance', [
            'accountType' => $accountType,
        ]);
    }

    /**
     * Разместить ордер на Bybit.
     * POST /v5/order/create
     *
     * @param array $params Параметры ордера (category, symbol, side, orderType, qty, ...)
     * @return array
     */
    public function placeOrder(array $params): array
    {
        return $this->postSigned('/v5/order/create', $params);
    }

    /**
     * Отменить ордер по orderLinkId.
     * POST /v5/order/cancel
     *
     * @param string $symbol
     * @param string $orderLinkId
     * @return array
     */
    public function cancelOrder(string $symbol, string $orderLinkId): array
    {
        return $this->postSigned('/v5/order/cancel', [
            'category'    => 'linear',
            'symbol'      => $symbol,
            'orderLinkId' => $orderLinkId,
        ]);
    }

    /**
     * Установить TP/SL/trailing на открытой позиции.
     * POST /v5/position/trading-stop
     *
     * @param array $params Поля: category, symbol, side, takeProfit, stopLoss,
     *                      trailingStop, activePrice, tpTriggerBy, slTriggerBy, ...
     * @return array
     */
    public function setTradingStop(array $params): array
    {
        return $this->postSigned('/v5/position/trading-stop', $params);
    }

    /**
     * Установить плечо для символа.
     * POST /v5/position/set-leverage
     *
     * @param string $symbol
     * @param int    $leverage
     * @return array
     */
    public function setLeverage(string $symbol, int $leverage): array
    {
        return $this->postSigned('/v5/position/set-leverage', [
            'category'     => 'linear',
            'symbol'       => $symbol,
            'buyLeverage'  => (string)$leverage,
            'sellLeverage' => (string)$leverage,
        ]);
    }

    /**
     * Переключить margin mode для символа.
     * POST /v5/position/switch-isolated
     *
     * @param string $symbol
     * @param string $mode 'cross'|'isolated'
     * @param int    $leverage Плечо (обязательно для Bybit)
     * @return array
     */
    public function switchMarginMode(string $symbol, string $mode, int $leverage = 10): array
    {
        // Bybit: tradeMode 0=cross, 1=isolated
        $tradeMode = ($mode === 'isolated') ? 1 : 0;
        return $this->postSigned('/v5/position/switch-isolated', [
            'category'     => 'linear',
            'symbol'       => $symbol,
            'tradeMode'    => $tradeMode,
            'buyLeverage'  => (string)$leverage,
            'sellLeverage' => (string)$leverage,
        ]);
    }

    /**
     * Получить список открытых позиций.
     * GET /v5/position/list (signed)
     *
     * @param string|null $symbol Если null — вернуть все позиции category=linear
     * @return array
     */
    public function getPositions(?string $symbol = null): array
    {
        $query = ['category' => 'linear', 'settleCoin' => 'USDT'];
        if ($symbol !== null) {
            $query['symbol'] = $symbol;
        }
        return $this->getSigned('/v5/position/list', $query);
    }

    /**
     * Получить открытые ордера.
     * GET /v5/order/realtime (signed)
     *
     * @param string|null $symbol
     * @return array
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        // v0.9.0-step8: Bybit V5 разделяет active vs stop ордера по orderFilter.
        // Без orderFilter возвращаются только Order (обычные limit/market) —
        // conditional/stop не попадают в выдачу. Делаем два запроса и сливаем.
        // Следствия того что не брали stop раньше — ложные cancel для avg/sl conditional.
        $base = ['category' => 'linear', 'settleCoin' => 'USDT', 'limit' => 50];
        if ($symbol !== null) {
            $base['symbol'] = $symbol;
        }

        $merged = ['category' => 'success', 'ret_msg' => 'OK', 'result' => ['list' => []]];
        $hadError = null;

        foreach (['Order', 'StopOrder'] as $filter) {
            $query = $base;
            $query['orderFilter'] = $filter;

            // Пагинация по cursor (на случай более 50 ордеров).
            $cursor = null;
            $safety = 0;
            do {
                if ($cursor !== null && $cursor !== '') {
                    $query['cursor'] = $cursor;
                } else {
                    unset($query['cursor']);
                }
                $resp = $this->getSigned('/v5/order/realtime', $query);
                if (($resp['category'] ?? null) !== Errors::SUCCESS) {
                    $hadError = $resp['ret_msg'] ?? 'unknown';
                    break;
                }
                $list   = $resp['result']['list']           ?? [];
                foreach ($list as $row) {
                    $merged['result']['list'][] = $row;
                }
                $cursor = $resp['result']['nextPageCursor'] ?? null;
                $safety++;
            } while ($cursor !== null && $cursor !== '' && $safety < 20);
        }

        if ($hadError !== null && empty($merged['result']['list'])) {
            // Оба запроса вернули ошибку — пробрасываем наверх.
            return ['category' => 'error', 'ret_msg' => $hadError, 'result' => ['list' => []]];
        }
        return $merged;
    }

    /**
     * Получить историю исполнений (fills).
     * GET /v5/execution/list (signed)
     *
     * @param string      $symbol
     * @param string|null $orderLinkId Если указан — только для этого ордера
     * @return array
     */
    public function getExecutions(string $symbol, ?string $orderLinkId = null): array
    {
        $query = ['category' => 'linear', 'symbol' => $symbol, 'limit' => 50];
        if ($orderLinkId !== null) {
            $query['orderLinkId'] = $orderLinkId;
        }
        return $this->getSigned('/v5/execution/list', $query);
    }

    /**
     * Получить текущую ставку финансирования.
     * GET /v5/market/tickers (публичный)
     *
     * @param string $symbol
     * @return array
     */
    public function getFundingRate(string $symbol): array
    {
        return $this->get('/v5/market/tickers', [
            'category' => 'linear',
            'symbol'   => $symbol,
        ]);
    }

    /**
     * Изменить активный ордер (amend).
     * POST /v5/order/amend
     *
     * Применяется для изменения qty, price, triggerPrice, sl, tp у ещё не исполненного ордера.
     * Если изменение невозможно (ордер уже в обработке), Bybit вернёт retCode ≠ 0.
     *
     * @param array $params Обязательные: category, symbol и один из orderId/orderLinkId.
     *                      Опциональные: qty, price, triggerPrice, takeProfit, stopLoss, ...
     * @return array
     */
    public function amendOrder(array $params): array
    {
        return $this->postSigned('/v5/order/amend', $params);
    }
}
