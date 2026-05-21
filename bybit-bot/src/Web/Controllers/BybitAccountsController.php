<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Bybit\SecretsService;
use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use BybitBot\Exchange\AdapterFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * BybitAccountsController — управление множеством Bybit-аккаунтов (v0.9.0).
 *
 * GET  /accounts                      — список аккаунтов
 * POST /accounts/create               — создать аккаунт (имя+network+ключи, с тестом ключей)
 * POST /accounts/{id}/update-keys     — обновить ключи (с тестом)
 * POST /accounts/{id}/rename          — переименовать
 * POST /accounts/{id}/toggle          — enabled on/off
 * POST /accounts/{id}/archive         — soft-delete (только если 0 открытых позиций)
 * POST /accounts/{id}/test            — тест ключей
 *
 * Flash через cookie 'acc_flash' формата 'OK|...' или 'ERR|...' (как в SettingsController).
 */
final class BybitAccountsController
{
    private const FLASH_COOKIE = 'acc_flash';

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $twig = Twig::fromRequest($request);

        $accounts = BybitAccountsRepo::listAll(false); // без архивированных

        // Дополняем UI-информацией: статус секретов + кол-во открытых позиций.
        foreach ($accounts as &$a) {
            $st = SecretsService::accountKeysStatus((int)$a['id']);
            $a['keys_configured'] = $st['configured'];
            $a['keys_mask']       = $st['api_key_mask'] !== '' ? $st['api_key_mask'] : $a['api_key_mask'];
            $a['keys_mtime']      = $st['mtime'];
            $a['open_trades']     = BybitAccountsRepo::openTradesCount((int)$a['id']);
        }
        unset($a);

        // flash из cookie
        $cookies = $request->getCookieParams();
        $flashRaw = (string)($cookies[self::FLASH_COOKIE] ?? '');
        $flashType = null;
        $flashMsg  = '';
        if ($flashRaw !== '') {
            $parts = explode('|', $flashRaw, 2);
            $flashType = strtolower($parts[0]) === 'ok' ? 'ok' : 'err';
            $flashMsg  = $parts[1] ?? '';
        }

        return $twig->render($response, 'accounts.twig', [
            'accounts'    => $accounts,
            'mode'        => (string)Config::get('mode', null, 'paper'),
            'app_version' => (string)Config::bootstrap('app.version', '?'),
            'flash_type'  => $flashType,
            'flash_msg'   => $flashMsg,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = (array)$request->getParsedBody();
        $name    = trim((string)($params['name']    ?? ''));
        $network = trim((string)($params['network'] ?? 'live'));
        $apiKey  = trim((string)($params['api_key']    ?? ''));
        $secret  = trim((string)($params['api_secret'] ?? ''));

        $flash = '';
        $id    = 0;
        try {
            if ($name === '' || $apiKey === '' || $secret === '') {
                throw new \RuntimeException('Заполните имя, API key и secret');
            }
            // Тест ключей ОБЯЗАТЕЛЕН до записи (защита от опечаток).
            $test = SecretsService::testKeys($apiKey, $secret, $network);
            if (!$test['ok']) {
                throw new \RuntimeException('Тест ключей не прошёл: ' . $test['message']);
            }

            $id = BybitAccountsRepo::create($name, $network, $apiKey, true);
            SecretsService::saveAccountKeys($id, $apiKey, $secret);
            AdapterFactory::resetAccount($id);

            EventRecorder::event(EventRecorder::WARN, 'bybit_account_created', null, [
                'account_id' => $id, 'name' => $name, 'network' => $network,
                'balance_usdt' => $test['balance_usdt'],
            ]);
            $bal = $test['balance_usdt'] !== null ? sprintf('%.2f USDT', $test['balance_usdt']) : 'n/a';
            $flash = "OK|Аккаунт '{$name}' создан (id={$id}). Тест: OK, баланс UNIFIED: {$bal}.";
        } catch (\Throwable $e) {
            Logger::get()->error('BybitAccountsController::create failed', ['error' => $e->getMessage()]);
            EventRecorder::event(EventRecorder::ERROR, 'bybit_account_create_failed', null, [
                'name' => $name, 'error' => $e->getMessage(),
            ]);
            // Если успели создать запись в bybit_accounts, но провалили saveAccountKeys —
            // нет, в текущем порядке БД-запись делается ПОСЛЕ успешного test, и до saveAccountKeys.
            // Откат не нужен — пользователь сможет дозаполнить ключи через "Обновить ключи".
            $flash = 'ERR|' . $e->getMessage();
        }
        return $this->redirect($response, $flash);
    }

    public function updateKeys(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        $params = (array)$request->getParsedBody();
        $apiKey = trim((string)($params['api_key']    ?? ''));
        $secret = trim((string)($params['api_secret'] ?? ''));

        $flash = '';
        try {
            $acc = BybitAccountsRepo::find($id);
            if ($acc === null) throw new \RuntimeException("Аккаунт #{$id} не найден");
            if ($apiKey === '' || $secret === '') throw new \RuntimeException('Заполните API key и secret');

            $test = SecretsService::testKeys($apiKey, $secret, (string)$acc['network']);
            if (!$test['ok']) throw new \RuntimeException('Тест ключей не прошёл: ' . $test['message']);

            SecretsService::saveAccountKeys($id, $apiKey, $secret);
            BybitAccountsRepo::updateApiKeyMask($id, $apiKey);
            AdapterFactory::resetAccount($id);

            EventRecorder::event(EventRecorder::WARN, 'bybit_account_keys_updated', null, [
                'account_id' => $id, 'name' => $acc['name'], 'balance_usdt' => $test['balance_usdt'],
            ]);
            $bal = $test['balance_usdt'] !== null ? sprintf('%.2f USDT', $test['balance_usdt']) : 'n/a';
            $flash = "OK|Ключи аккаунта '{$acc['name']}' обновлены. Тест: OK, баланс: {$bal}.";
        } catch (\Throwable $e) {
            Logger::get()->error('BybitAccountsController::updateKeys failed', ['error' => $e->getMessage()]);
            EventRecorder::event(EventRecorder::ERROR, 'bybit_account_keys_update_failed', null, [
                'account_id' => $id, 'error' => $e->getMessage(),
            ]);
            $flash = 'ERR|' . $e->getMessage();
        }
        return $this->redirect($response, $flash);
    }

    public function rename(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        $params = (array)$request->getParsedBody();
        $newName = trim((string)($params['name'] ?? ''));

        $flash = '';
        try {
            $acc = BybitAccountsRepo::find($id);
            if ($acc === null) throw new \RuntimeException("Аккаунт #{$id} не найден");

            BybitAccountsRepo::rename($id, $newName);
            EventRecorder::event(EventRecorder::INFO, 'bybit_account_renamed', null, [
                'account_id' => $id, 'old_name' => $acc['name'], 'new_name' => $newName,
            ]);
            $flash = "OK|Аккаунт переименован: '{$acc['name']}' → '{$newName}'.";
        } catch (\Throwable $e) {
            $flash = 'ERR|' . $e->getMessage();
        }
        return $this->redirect($response, $flash);
    }

    public function toggle(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        $flash = '';
        try {
            $acc = BybitAccountsRepo::find($id);
            if ($acc === null) throw new \RuntimeException("Аккаунт #{$id} не найден");

            $next = !$acc['enabled'];
            BybitAccountsRepo::setEnabled($id, $next);
            EventRecorder::event(EventRecorder::INFO, 'bybit_account_toggle', null, [
                'account_id' => $id, 'name' => $acc['name'], 'enabled' => $next,
            ]);
            $flash = "OK|Аккаунт '{$acc['name']}' — " . ($next ? 'ВКЛЮЧЁН' : 'ВЫКЛЮЧЕН')
                . '. Открытые позиции продолжают сопровождаться.';
        } catch (\Throwable $e) {
            $flash = 'ERR|' . $e->getMessage();
        }
        return $this->redirect($response, $flash);
    }

    /**
     * v0.9.0-step7 task5: вкл/выкл отдельной стратегии в аккаунте.
     * POST /accounts/{id}/toggle-strategy/{strat}
     */
    public function toggleStrategy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id    = (int)($args['id'] ?? 0);
        $strat = (string)($args['strat'] ?? '');
        $flash = '';
        try {
            $acc = BybitAccountsRepo::find($id);
            if ($acc === null) throw new \RuntimeException("Аккаунт #{$id} не найден");
            if (!in_array($strat, ['s1', 's2', 's3'], true)) {
                throw new \RuntimeException("Неизвестная стратегия: {$strat}");
            }
            $key = $strat . '_enabled';
            $cur = (bool)($acc[$key] ?? true);
            $next = !$cur;
            BybitAccountsRepo::setStrategyEnabled($id, $strat, $next);
            EventRecorder::event(EventRecorder::INFO, 'bybit_account_toggle_strategy', null, [
                'account_id' => $id, 'name' => $acc['name'], 'strategy' => $strat, 'enabled' => $next,
            ]);
            $flash = "OK|Аккаунт '{$acc['name']}' — стратегия " . strtoupper($strat) . ' '
                . ($next ? 'ВКЛЮЧЕНА' : 'ВЫКЛЮЧЕНА');
        } catch (\Throwable $e) {
            $flash = 'ERR|' . $e->getMessage();
        }
        return $this->redirect($response, $flash);
    }

    public function archive(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        $flash = '';
        try {
            $acc = BybitAccountsRepo::find($id);
            if ($acc === null) throw new \RuntimeException("Аккаунт #{$id} не найден");

            BybitAccountsRepo::archive($id); // бросит исключение если open_trades > 0
            EventRecorder::event(EventRecorder::WARN, 'bybit_account_archived', null, [
                'account_id' => $id, 'name' => $acc['name'],
            ]);
            $flash = "OK|Аккаунт '{$acc['name']}' архивирован (soft-delete).";
        } catch (\Throwable $e) {
            $flash = 'ERR|' . $e->getMessage();
        }
        return $this->redirect($response, $flash);
    }

    public function test(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        $flash = '';
        try {
            $acc  = BybitAccountsRepo::find($id);
            if ($acc === null) throw new \RuntimeException("Аккаунт #{$id} не найден");

            $keys = SecretsService::readAccountKeys($id);
            if ($keys === null) throw new \RuntimeException("Ключи аккаунта #{$id} не настроены");

            $t = SecretsService::testKeys($keys['api_key'], $keys['api_secret'], (string)$acc['network']);
            if ($t['ok']) {
                EventRecorder::event(EventRecorder::INFO, 'bybit_account_tested', null, [
                    'account_id' => $id, 'name' => $acc['name'], 'balance_usdt' => $t['balance_usdt'],
                ]);
                $bal = $t['balance_usdt'] !== null ? sprintf('%.2f USDT', $t['balance_usdt']) : 'n/a';
                $flash = "OK|Тест '{$acc['name']}': OK. Баланс UNIFIED: {$bal}.";
            } else {
                EventRecorder::event(EventRecorder::WARN, 'bybit_account_test_failed', null, [
                    'account_id' => $id, 'name' => $acc['name'], 'message' => $t['message'],
                ]);
                $flash = 'ERR|Тест не прошёл: ' . $t['message'];
            }
        } catch (\Throwable $e) {
            $flash = 'ERR|' . $e->getMessage();
        }
        return $this->redirect($response, $flash);
    }

    private function redirect(ResponseInterface $response, string $flash): ResponseInterface
    {
        return $response
            ->withHeader('Location', '/accounts')
            ->withHeader('Set-Cookie', self::FLASH_COOKIE . '=' . rawurlencode($flash) . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict')
            ->withStatus(302);
    }
}
