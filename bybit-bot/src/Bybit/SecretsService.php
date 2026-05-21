<?php
declare(strict_types=1);

namespace BybitBot\Bybit;

use BybitBot\Core\Config;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;

/**
 * Безопасное хранение Bybit API ключей для live-режима.
 *
 * Ключи хранятся в отдельном PHP-файле в каталоге paths.secrets (по умолчанию data/secrets/),
 * вне git, с правами 0600. Файл подгружается в Bootstrap после .env через putenv()/getenv()
 * и переопределяет переменные BYBIT_API_KEY_MAINNET / BYBIT_API_SECRET_MAINNET.
 *
 * Никогда не возвращаем secret в ответах — только маску (последние 4 символа).
 *
 * v0.8.0 (#11) — Live-торговля.
 */
final class SecretsService
{
    /** Имя файла с ключами в SECRETS_DIR. */
    public const LIVE_KEYS_FILE = 'live_keys.php';

    /**
     * Полный путь к файлу с ключами (legacy, до v0.9.0 — единственный live-аккаунт).
     */
    public static function liveKeysPath(): string
    {
        $dir = (string)Config::bootstrap('paths.secrets');
        return rtrim($dir, '/') . '/' . self::LIVE_KEYS_FILE;
    }

    // ── v0.9.0: per-account ключи ─────────────────────────────────────

    /**
     * Путь к файлу ключей конкретного аккаунта.
     * Файлы: data/secrets/account_{id}.php, chmod 0600.
     */
    public static function accountKeysPath(int $accountId): string
    {
        $dir = (string)Config::bootstrap('paths.secrets');
        return rtrim($dir, '/') . '/account_' . $accountId . '.php';
    }

    /**
     * @return array{api_key:string, api_secret:string}|null
     */
    public static function readAccountKeys(int $accountId): ?array
    {
        $path = self::accountKeysPath($accountId);
        if (!is_file($path)) {
            return null;
        }
        try {
            $data = require $path;
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($data)) return null;
        $key    = isset($data['api_key'])    ? (string)$data['api_key']    : '';
        $secret = isset($data['api_secret']) ? (string)$data['api_secret'] : '';
        if ($key === '' || $secret === '') return null;
        return ['api_key' => $key, 'api_secret' => $secret];
    }

    /**
     * Атомарная запись ключей аккаунта в файл account_{id}.php (chmod 0600).
     *
     * @throws \RuntimeException при пустых/невалидных значениях или ошибке записи
     */
    public static function saveAccountKeys(int $accountId, string $apiKey, string $apiSecret): void
    {
        $apiKey    = trim($apiKey);
        $apiSecret = trim($apiSecret);

        if ($apiKey === '' || $apiSecret === '') {
            throw new \RuntimeException('API key и secret не могут быть пустыми');
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $apiKey) || !preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $apiSecret)) {
            throw new \RuntimeException('API key / secret содержат недопустимые символы или слишком короткие');
        }

        $target = self::accountKeysPath($accountId);
        $dir    = dirname($target);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException("Не удалось создать каталог секретов: {$dir}");
            }
        }

        $content = "<?php\n"
            . "// Bybit API credentials для account_id={$accountId}. Чувствительные данные.\n"
            . "// НЕ КОММИТИТЬ В GIT. Права 0600.\n"
            . "// Создан/обновлён: " . gmdate('Y-m-d\\TH:i:s\\Z') . "\n"
            . "return [\n"
            . "    'api_key'    => " . var_export($apiKey, true) . ",\n"
            . "    'api_secret' => " . var_export($apiSecret, true) . ",\n"
            . "];\n";

        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Не удалось записать temp-файл: {$tmp}");
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Не удалось переименовать temp в {$target}");
        }
        @chmod($target, 0600);
    }

    /**
     * Удалить файл с ключами аккаунта.
     */
    public static function deleteAccountKeys(int $accountId): void
    {
        $path = self::accountKeysPath($accountId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array{configured:bool, api_key_mask:string, file_exists:bool, mtime:?string}
     */
    public static function accountKeysStatus(int $accountId): array
    {
        $path   = self::accountKeysPath($accountId);
        $exists = is_file($path);
        if (!$exists) {
            return ['configured' => false, 'api_key_mask' => '', 'file_exists' => false, 'mtime' => null];
        }
        $data = self::readAccountKeys($accountId);
        $configured = $data !== null;
        $mask = '';
        if ($configured) {
            $k = $data['api_key'];
            $len = strlen($k);
            $mask = $len <= 4 ? str_repeat('*', $len) : (str_repeat('*', $len - 4) . substr($k, -4));
        }
        $mt = @filemtime($path);
        return [
            'configured'   => $configured,
            'api_key_mask' => $mask,
            'file_exists'  => $exists,
            'mtime'        => $mt ? gmdate('Y-m-d\\TH:i:s\\Z', $mt) : null,
        ];
    }

    /**
     * Тест произвольной пары ключей под выбранную сеть.
     *
     * Новая версия testLiveKeys() с явным $network: 'live'|'testnet'.
     *
     * @return array{ok:bool, message:string, http_code:int, ret_code:?int, ret_msg:?string, balance_usdt:?float}
     */
    public static function testKeys(string $apiKey, string $apiSecret, string $network = 'live'): array
    {
        if (!in_array($network, ['live', 'testnet'], true)) {
            return [
                'ok' => false, 'message' => "network должен быть live|testnet, получено: {$network}",
                'http_code' => 0, 'ret_code' => null, 'ret_msg' => null, 'balance_usdt' => null,
            ];
        }
        $baseUrl = $network === 'live'
            ? (string)Config::bootstrap('bybit.base_url_mainnet', 'https://api.bybit.com')
            : (string)Config::bootstrap('bybit.base_url_testnet', 'https://api-testnet.bybit.com');
        return self::testKeysAgainstUrl($apiKey, $apiSecret, $baseUrl);
    }

    /**
     * Загрузить ключи из файла и записать в putenv()/$_ENV/$_SERVER,
     * чтобы Client::default() их увидел. Безопасно: ничего не делает если файла нет.
     *
     * Вызывается из Bootstrap после Dotenv.
     */
    public static function loadIntoEnv(): void
    {
        $path = self::liveKeysPath();
        if (!is_file($path)) {
            return;
        }
        try {
            $data = require $path;
        } catch (\Throwable $e) {
            // повреждённый файл — логируем, но не падаем
            if (class_exists(Logger::class)) {
                try { Logger::get()->warning('SecretsService: live_keys.php read failed', ['error' => $e->getMessage()]); } catch (\Throwable $_) {}
            }
            return;
        }
        if (!is_array($data)) {
            return;
        }
        $key    = isset($data['api_key'])    ? (string)$data['api_key']    : '';
        $secret = isset($data['api_secret']) ? (string)$data['api_secret'] : '';
        if ($key !== '' && $secret !== '') {
            // Переопределяем только переменные mainnet (live). Testnet не трогаем.
            $_ENV['BYBIT_API_KEY_MAINNET']    = $key;
            $_ENV['BYBIT_API_SECRET_MAINNET'] = $secret;
            $_SERVER['BYBIT_API_KEY_MAINNET']    = $key;
            $_SERVER['BYBIT_API_SECRET_MAINNET'] = $secret;
            putenv('BYBIT_API_KEY_MAINNET=' . $key);
            putenv('BYBIT_API_SECRET_MAINNET=' . $secret);
        }
    }

    /**
     * Записать ключи в файл атомарно (через temp + rename), права 0600.
     *
     * @throws \RuntimeException при ошибке записи или валидации
     */
    public static function saveLiveKeys(string $apiKey, string $apiSecret): void
    {
        $apiKey    = trim($apiKey);
        $apiSecret = trim($apiSecret);

        if ($apiKey === '' || $apiSecret === '') {
            throw new \RuntimeException('API key и secret не могут быть пустыми');
        }
        // Bybit ключи — буквенно-цифровые. Минимальная sanity-проверка.
        if (!preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $apiKey) || !preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $apiSecret)) {
            throw new \RuntimeException('API key / secret содержат недопустимые символы или слишком короткие');
        }

        $dir = dirname(self::liveKeysPath());
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException("Не удалось создать каталог секретов: {$dir}");
            }
        }

        $content = "<?php\n"
            . "// Bybit live API credentials. Файл хранит чувствительные данные.\n"
            . "// НЕ КОММИТИТЬ В GIT. Права 0600.\n"
            . "// Управление через UI: /settings → Live-торговля.\n"
            . "// Создан/обновлён: " . gmdate('Y-m-d\\TH:i:s\\Z') . "\n"
            . "return [\n"
            . "    'api_key'    => " . var_export($apiKey, true) . ",\n"
            . "    'api_secret' => " . var_export($apiSecret, true) . ",\n"
            . "];\n";

        $target = self::liveKeysPath();
        $tmp    = $target . '.tmp.' . bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Не удалось записать temp-файл: {$tmp}");
        }
        @chmod($tmp, 0600);

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Не удалось переименовать temp в {$target}");
        }
        @chmod($target, 0600);

        // Сразу подгрузим в env, чтобы текущий запрос увидел новые ключи.
        self::loadIntoEnv();
    }

    /**
     * Удалить файл с ключами (отключение live).
     */
    public static function deleteLiveKeys(): void
    {
        $path = self::liveKeysPath();
        if (is_file($path)) {
            @unlink($path);
        }
        // Чистим env (на случай, если ключи уже были подгружены в этом процессе).
        unset($_ENV['BYBIT_API_KEY_MAINNET'], $_ENV['BYBIT_API_SECRET_MAINNET']);
        unset($_SERVER['BYBIT_API_KEY_MAINNET'], $_SERVER['BYBIT_API_SECRET_MAINNET']);
        putenv('BYBIT_API_KEY_MAINNET');
        putenv('BYBIT_API_SECRET_MAINNET');
    }

    /**
     * Прочитать текущие ключи (только для внутренних целей — тестов).
     *
     * @return array{api_key:string, api_secret:string}|null
     */
    public static function readLiveKeys(): ?array
    {
        $path = self::liveKeysPath();
        if (!is_file($path)) {
            return null;
        }
        try {
            $data = require $path;
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($data)) return null;
        $key    = isset($data['api_key'])    ? (string)$data['api_key']    : '';
        $secret = isset($data['api_secret']) ? (string)$data['api_secret'] : '';
        if ($key === '' || $secret === '') return null;
        return ['api_key' => $key, 'api_secret' => $secret];
    }

    /**
     * Безопасное состояние ключей для UI (никогда не возвращает secret).
     *
     * @return array{configured:bool, api_key_mask:string, file_exists:bool, mtime:?string}
     */
    public static function liveKeysStatus(): array
    {
        $path = self::liveKeysPath();
        $exists = is_file($path);
        if (!$exists) {
            return [
                'configured'   => false,
                'api_key_mask' => '',
                'file_exists'  => false,
                'mtime'        => null,
            ];
        }
        $data = self::readLiveKeys();
        $configured = $data !== null;
        $mask = '';
        if ($configured) {
            $k = $data['api_key'];
            $len = strlen($k);
            if ($len <= 4) {
                $mask = str_repeat('*', $len);
            } else {
                $mask = str_repeat('*', max(0, $len - 4)) . substr($k, -4);
            }
        }
        $mt = @filemtime($path);
        return [
            'configured'   => $configured,
            'api_key_mask' => $mask,
            'file_exists'  => $exists,
            'mtime'        => $mt ? gmdate('Y-m-d\\TH:i:s\\Z', $mt) : null,
        ];
    }

    /**
     * Тест live ключей через /v5/account/wallet-balance на mainnet.
     *
     * v0.9.0: обёртка над testKeysAgainstUrl(). Оставлена для обратной совместимости
     * с SettingsController. Предпочитайте testKeys(…, network).
     *
     * @return array{ok:bool, message:string, http_code:int, ret_code:?int, ret_msg:?string, balance_usdt:?float}
     */
    public static function testLiveKeys(string $apiKey, string $apiSecret): array
    {
        return self::testKeys($apiKey, $apiSecret, 'live');
    }

    /**
     * Общая реализация теста ключей против произвольного baseUrl (mainnet/testnet).
     *
     * @return array{ok:bool, message:string, http_code:int, ret_code:?int, ret_msg:?string, balance_usdt:?float}
     */
    private static function testKeysAgainstUrl(string $apiKey, string $apiSecret, string $baseUrl): array
    {
        $apiKey    = trim($apiKey);
        $apiSecret = trim($apiSecret);
        if ($apiKey === '' || $apiSecret === '') {
            return [
                'ok' => false,
                'message' => 'Пустые ключи',
                'http_code' => 0,
                'ret_code' => null,
                'ret_msg' => null,
                'balance_usdt' => null,
            ];
        }

        $recvWindow = (int)Config::bootstrap('bybit.recv_window', 5000);

        $signer = new Signer($apiKey, $apiSecret, $recvWindow);

        // Собираем GET-запрос вручную: /v5/account/wallet-balance?accountType=UNIFIED
        $query = http_build_query(['accountType' => 'UNIFIED']);
        $headers = $signer->headers($query);

        $url = rtrim($baseUrl, '/') . '/v5/account/wallet-balance?' . $query;

        $ch = curl_init();
        $hdrLines = [];
        foreach ($headers as $k => $v) $hdrLines[] = $k . ': ' . $v;
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $hdrLines,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR    => false,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            return [
                'ok' => false,
                'message' => 'Сетевая ошибка: ' . ($err !== '' ? $err : 'нет ответа'),
                'http_code' => $http,
                'ret_code' => null,
                'ret_msg' => null,
                'balance_usdt' => null,
            ];
        }

        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return [
                'ok' => false,
                'message' => 'Невалидный JSON-ответ от Bybit',
                'http_code' => $http,
                'ret_code' => null,
                'ret_msg' => null,
                'balance_usdt' => null,
            ];
        }

        $retCode = isset($json['retCode']) ? (int)$json['retCode'] : null;
        $retMsg  = isset($json['retMsg']) ? (string)$json['retMsg'] : null;

        if ($retCode !== 0) {
            return [
                'ok' => false,
                'message' => 'Bybit отклонил ключи: ' . ($retMsg ?: 'неизвестная ошибка'),
                'http_code' => $http,
                'ret_code' => $retCode,
                'ret_msg' => $retMsg,
                'balance_usdt' => null,
            ];
        }

        // Парсим USDT баланс (best effort).
        $bal = null;
        $coins = $json['result']['list'][0]['coin'] ?? null;
        if (is_array($coins)) {
            foreach ($coins as $c) {
                if (is_array($c) && (($c['coin'] ?? null) === 'USDT')) {
                    $bal = isset($c['walletBalance']) ? (float)$c['walletBalance'] : null;
                    break;
                }
            }
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'http_code' => $http,
            'ret_code' => $retCode,
            'ret_msg' => $retMsg,
            'balance_usdt' => $bal,
        ];
    }
}
