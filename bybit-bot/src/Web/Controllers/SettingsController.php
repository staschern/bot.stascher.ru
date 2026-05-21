<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Bybit\MarketInfo;
use BybitBot\Bybit\SecretsService;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * SettingsController — управление настройками бота через Web UI.
 *
 * GET  /settings       — отрисовать форму настроек
 * POST /settings       — сохранить настройки
 * POST /settings/action — выполнить действие (paper-close-all, reconcile-now, deposit-refresh)
 *
 * См. spec.md §14 (v0.5.0).
 */
final class SettingsController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $twig = Twig::fromRequest($request);
        $pdo  = Database::pdo();

        // Загружаем все настройки из БД
        $settings = [];
        $rows = $pdo->query('SELECT key, value FROM settings ORDER BY key')->fetchAll();
        $boolKeys = [
            'daily_drawdown.enabled', 'signal_upper_cap.enabled',
            'long_short_balance.enabled', 'funding_filter.enabled',
        ];
        foreach ($rows as $r) {
            $key = (string)$r['key'];
            $val = (string)$r['value'];
            // Для bool-ключей нормализуем legacy-значения 'true'/'false'/'' → '1'/'0'
            // (чтобы Twig однозначно понимал вывод, без loose-сравнения).
            if (in_array($key, $boolKeys, true)) {
                $val = ($val === '1' || $val === 'true') ? '1' : '0';
            }
            $settings[$key] = $val;
        }

        // Стратегии
        $strategies = $pdo->query(
            'SELECT id, name, enabled, is_automatic FROM strategies ORDER BY id'
        )->fetchAll();

        // Стейблкоины (список по строке)
        $stablecoinsRaw = (string)Config::get('stablecoins_list', null, '');
        $stablecoins = implode("\n", array_filter(array_map('trim', explode(',', $stablecoinsRaw))));
        if ($stablecoins === '' && $stablecoinsRaw !== '') {
            $stablecoins = $stablecoinsRaw;
        }

        // Настройки стратегий
        $stratSettings = [];
        $stratRows = $pdo->query('SELECT strategy_id, key, value FROM strategy_settings ORDER BY strategy_id, key')->fetchAll();
        foreach ($stratRows as $sr) {
            $stratSettings[(string)$sr['strategy_id']][(string)$sr['key']] = $sr['value'];
        }

        // Flash message после POST
        $flash = '';
        $cookies = $request->getCookieParams();
        if (!empty($cookies['settings_saved'])) {
            $flash = 'Настройки сохранены.';
        }

        // v0.7.5: ручные алиасы тикеров (пока только bybit)
        try {
            $aliases = MarketInfo::listAliases('bybit');
        } catch (\Throwable $e) {
            $aliases = [];
            Logger::get()->warning('settings: listAliases failed', ['error' => $e->getMessage()]);
        }
        $aliasFlash = '';
        if (!empty($cookies['alias_flash'])) {
            $aliasFlash = (string)$cookies['alias_flash'];
        }
        $instrumentsCount = MarketInfo::instrumentsCount();

        // v0.8.0: статус live API ключей (никогда не выводится secret).
        $liveKeysStatus = SecretsService::liveKeysStatus();
        $liveFlash = '';
        if (!empty($cookies['live_flash'])) {
            $liveFlash = (string)$cookies['live_flash'];
        }

        return $twig->render($response, 'settings.twig', [
            'mode'              => (string)Config::get('mode', null, 'paper'),
            'app_version'       => (string)Config::bootstrap('app.version', '0.7.0'),
            'settings'          => $settings,
            'strategies'        => $strategies,
            'strat_settings'    => $stratSettings,
            'stablecoins'       => $stablecoins,
            'flash'             => $flash,
            'aliases'           => $aliases,
            'alias_flash'       => $aliasFlash,
            'instruments_count' => $instrumentsCount,
            'live_keys'         => $liveKeysStatus,
            'live_flash'        => $liveFlash,
        ]);
    }

    /**
     * v0.8.0: POST /settings/live-keys — сохранение Bybit live API ключей с тестом.
     *
     * Алгоритм:
     *  1. Проверка формата ключей.
     *  2. Тестовый запрос /v5/account/wallet-balance на mainnet.
     *  3. При успехе — сохранение в data/secrets/live_keys.php и event live_keys_saved.
     *  4. При ошибке — event live_test_failed, ключи НЕ сохраняются.
     */
    public function saveLiveKeys(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $apiKey    = trim((string)($body['live_api_key']    ?? ''));
        $apiSecret = trim((string)($body['live_api_secret'] ?? ''));

        $flash = '';
        if ($apiKey === '' || $apiSecret === '') {
            $flash = 'ERR|Заполните API key и secret.';
        } else {
            try {
                $test = SecretsService::testLiveKeys($apiKey, $apiSecret);
                if (!$test['ok']) {
                    EventRecorder::event(EventRecorder::ERROR, 'live_test_failed', null, [
                        'http_code' => $test['http_code'],
                        'ret_code'  => $test['ret_code'],
                        'ret_msg'   => $test['ret_msg'],
                    ]);
                    $flash = 'ERR|Тест ключей провален: ' . $test['message'] . ' Ключи НЕ сохранены.';
                } else {
                    SecretsService::saveLiveKeys($apiKey, $apiSecret);
                    EventRecorder::event(EventRecorder::WARN, 'live_keys_saved', null, [
                        'balance_usdt' => $test['balance_usdt'],
                    ]);
                    $bal = $test['balance_usdt'] !== null ? number_format($test['balance_usdt'], 2, '.', '') : '?';
                    $flash = 'OK|Ключи сохранены. Баланс USDT: ' . $bal;
                }
            } catch (\Throwable $e) {
                Logger::get()->error('saveLiveKeys failed', ['error' => $e->getMessage()]);
                EventRecorder::event(EventRecorder::ERROR, 'live_keys_save_failed', null, [
                    'error' => $e->getMessage(),
                ]);
                $flash = 'ERR|Ошибка сохранения: ' . $e->getMessage();
            }
        }

        return $response
            ->withHeader('Location', '/settings#live')
            ->withHeader('Set-Cookie', 'live_flash=' . rawurlencode($flash) . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict')
            ->withStatus(302);
    }

    /**
     * v0.8.0: POST /settings/live-keys/delete — удаление ключей.
     *
     * Блокирует удаление, если текущий mode = 'live' (сначала переключи на paper).
     */
    public function deleteLiveKeys(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $flash = '';
        $currentMode = (string)Config::get('mode', null, 'paper');
        if ($currentMode === 'live') {
            $flash = 'ERR|Сейчас mode=live. Сначала переключите режим на paper, затем удаляйте ключи.';
        } else {
            try {
                SecretsService::deleteLiveKeys();
                EventRecorder::event(EventRecorder::WARN, 'live_keys_deleted', null, []);
                $flash = 'OK|Ключи удалены.';
            } catch (\Throwable $e) {
                $flash = 'ERR|Ошибка: ' . $e->getMessage();
            }
        }
        return $response
            ->withHeader('Location', '/settings#live')
            ->withHeader('Set-Cookie', 'live_flash=' . rawurlencode($flash) . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict')
            ->withStatus(302);
    }

    /**
     * v0.8.0: POST /settings/live-keys/test — тестировать существующие сохранённые ключи.
     */
    public function testLiveKeys(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $flash = '';
        $keys = SecretsService::readLiveKeys();
        if ($keys === null) {
            $flash = 'ERR|Ключи не сохранены — нечего тестировать.';
        } else {
            $test = SecretsService::testLiveKeys($keys['api_key'], $keys['api_secret']);
            if ($test['ok']) {
                $bal = $test['balance_usdt'] !== null ? number_format($test['balance_usdt'], 2, '.', '') : '?';
                $flash = 'OK|Тест пройден. Баланс USDT: ' . $bal;
                EventRecorder::event(EventRecorder::INFO, 'live_keys_tested', null, [
                    'balance_usdt' => $test['balance_usdt'],
                ]);
            } else {
                $flash = 'ERR|' . $test['message'];
                EventRecorder::event(EventRecorder::ERROR, 'live_test_failed', null, [
                    'http_code' => $test['http_code'],
                    'ret_code'  => $test['ret_code'],
                    'ret_msg'   => $test['ret_msg'],
                ]);
            }
        }
        return $response
            ->withHeader('Location', '/settings#live')
            ->withHeader('Set-Cookie', 'live_flash=' . rawurlencode($flash) . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict')
            ->withStatus(302);
    }

    /**
     * v0.7.5: POST /settings/aliases/add — добавить/обновить алиас короткого тикера.
     */
    public function addAlias(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = (array)$request->getParsedBody();
        $src   = isset($body['source_symbol']) ? (string)$body['source_symbol'] : '';
        $tgt   = isset($body['bybit_symbol'])  ? (string)$body['bybit_symbol']  : '';
        $note  = isset($body['note']) && trim((string)$body['note']) !== '' ? (string)$body['note'] : null;
        $user  = (string)($request->getAttribute('username') ?? 'web');

        $msg = '';
        try {
            MarketInfo::addAlias($src, $tgt, $note, $user);
            $msg = 'Алиас ' . strtoupper(trim($src)) . ' → ' . strtoupper(trim($tgt)) . ' сохранён';
        } catch (\Throwable $e) {
            $msg = 'Ошибка: ' . $e->getMessage();
        }

        $cookie = 'alias_flash=' . rawurlencode($msg) . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict';
        return $response
            ->withHeader('Location', '/settings#aliases')
            ->withHeader('Set-Cookie', $cookie)
            ->withStatus(302);
    }

    /**
     * v0.7.5: POST /settings/aliases/delete — удалить алиас.
     */
    public function deleteAlias(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = (array)$request->getParsedBody();
        $src   = isset($body['source_symbol']) ? (string)$body['source_symbol'] : '';

        $msg = '';
        try {
            $ok = MarketInfo::deleteAlias($src, 'bybit');
            $msg = $ok ? ('Алиас ' . strtoupper(trim($src)) . ' удалён') : 'Алиас не найден';
        } catch (\Throwable $e) {
            $msg = 'Ошибка: ' . $e->getMessage();
        }

        $cookie = 'alias_flash=' . rawurlencode($msg) . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict';
        return $response
            ->withHeader('Location', '/settings#aliases')
            ->withHeader('Set-Cookie', $cookie)
            ->withStatus(302);
    }

    /**
     * v0.7.5.1: POST /settings/aliases/edit — обновить существующий алиас.
     * По факту это UPSERT через addAlias() — он уже умеет ON CONFLICT DO UPDATE.
     * При ручном редактировании сбрасываем created_by на username (было 'auto' — станет явным).
     */
    public function editAlias(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = (array)$request->getParsedBody();
        $src   = isset($body['source_symbol']) ? (string)$body['source_symbol'] : '';
        $tgt   = isset($body['bybit_symbol'])  ? (string)$body['bybit_symbol']  : '';
        $note  = isset($body['note']) && trim((string)$body['note']) !== '' ? (string)$body['note'] : null;
        $user  = (string)($request->getAttribute('username') ?? 'web');

        $msg = '';
        try {
            MarketInfo::addAlias($src, $tgt, $note, $user);
            $msg = 'Алиас ' . strtoupper(trim($src)) . ' → ' . strtoupper(trim($tgt)) . ' обновлён';
        } catch (\Throwable $e) {
            $msg = 'Ошибка: ' . $e->getMessage();
        }

        $cookie = 'alias_flash=' . rawurlencode($msg) . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict';
        return $response
            ->withHeader('Location', '/settings#aliases')
            ->withHeader('Set-Cookie', $cookie)
            ->withStatus(302);
    }

    /**
     * v0.7.5.1: POST /settings/aliases/backfill — импорт алиасов из истории signals.
     */
    public function backfillAliases(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $msg = '';
        try {
            $r = MarketInfo::backfillFromHistory();
            $msg = 'Импорт из истории: добавлено ' . $r['inserted']
                 . ', пропущено ' . $r['skipped']
                 . ' (всего уникальных пар ' . $r['total_pairs'] . ')';
        } catch (\Throwable $e) {
            $msg = 'Ошибка: ' . $e->getMessage();
        }

        $cookie = 'alias_flash=' . rawurlencode($msg) . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict';
        return $response
            ->withHeader('Location', '/settings#aliases')
            ->withHeader('Set-Cookie', $cookie)
            ->withStatus(302);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();

        // PHP конвертирует точки в именах полей POST в подчёркивания.
        // Восстанавливаем оригинальные ключи для bool-настроек и числовых
        // ключей с точкой, чтобы остальной код видел их в каноничном виде.
        $dotKeys = [
            'daily_drawdown_enabled'           => 'daily_drawdown.enabled',
            'daily_drawdown_pct'               => 'daily_drawdown.pct',
            'signal_upper_cap_enabled'         => 'signal_upper_cap.enabled',
            'signal_upper_cap_pct'             => 'signal_upper_cap.pct',
            'long_short_balance_enabled'       => 'long_short_balance.enabled',
            'long_short_balance_max_share_pct' => 'long_short_balance.max_share_pct',
            'long_short_balance_min_total_to_check' => 'long_short_balance.min_total_to_check',
            'funding_filter_enabled'           => 'funding_filter.enabled',
            'funding_filter_extreme_pct'       => 'funding_filter.extreme_pct',
            'min_lot_overshoot_pct'            => 'min_lot_overshoot.pct',
        ];
        foreach ($dotKeys as $from => $to) {
            if (array_key_exists($from, $body) && !array_key_exists($to, $body)) {
                $body[$to] = $body[$from];
            }
        }
        // CSRF — проверяем наличие user_id (уже проверено AuthMiddleware)
        $userId = $request->getAttribute('user_id');
        if ($userId === null) {
            $resp = $response->withHeader('Location', '/login')->withStatus(302);
            return $resp;
        }
        $allowedModes = ['paper', 'testnet', 'live', 'pause'];
        $allowedMarginModes = ['cross', 'isolated'];
        $diff   = [];
        $errors = [];
        // ── Режим ──
        if (isset($body['mode'])) {
            $newMode = (string)$body['mode'];
            if (in_array($newMode, $allowedModes, true)) {
                $old = (string)Config::get('mode', null, 'paper');
                if ($old !== $newMode) {
                    // v0.8.0: защита перехода в live — требуем ключи + явный CONFIRM.
                    if ($newMode === 'live') {
                        $status = SecretsService::liveKeysStatus();
                        // Нечувствительно к регистру и пробелам — не важно как введено: confirm / CONFIRM / Confirm.
                        $confirm = strtoupper(trim((string)($body['live_confirm'] ?? '')));
                        if (!$status['configured']) {
                            $errors[] = 'Сначала сохраните и проверьте live API ключи (блок «Live-торговля»).';
                        } elseif ($confirm !== 'CONFIRM') {
                            $errors[] = 'Для перехода в live введите слово CONFIRM в поле подтверждения (вы ввели: «' . htmlspecialchars((string)($body['live_confirm'] ?? '')) . '»).';
                        } else {
                            Config::set('mode', $newMode);
                            $diff['mode'] = ['from' => $old, 'to' => $newMode];

                            // v0.8.0.2: сразу после включения live тянем баланс и пишем snapshot,
                            // чтобы D0 не был 0 при первом открытии /dashboard.
                            $snapshotBalance = null;
                            try {
                                $keys = SecretsService::readLiveKeys();
                                if ($keys !== null) {
                                    $test = SecretsService::testLiveKeys($keys['api_key'], $keys['api_secret']);
                                    if ($test['ok'] && $test['balance_usdt'] !== null && $test['balance_usdt'] > 0) {
                                        $snapshotBalance = (float)$test['balance_usdt'];
                                        Database::pdo()->prepare(
                                            'INSERT INTO deposit_snapshots (mode, ts, value, source) VALUES (:m, :t, :v, :s)'
                                        )->execute([
                                            ':m' => 'live',
                                            ':t' => gmdate('Y-m-d\TH:i:s.v\Z'),
                                            ':v' => $snapshotBalance,
                                            ':s' => 'auto_on_live_enable',
                                        ]);
                                    }
                                }
                            } catch (\Throwable $e) {
                                Logger::get()->warning('live_enable: auto deposit snapshot failed', ['error' => $e->getMessage()]);
                            }

                            EventRecorder::event(EventRecorder::WARN, 'live_enabled', null, [
                                'from' => $old,
                                'api_key_mask' => $status['api_key_mask'],
                                'initial_balance_usdt' => $snapshotBalance,
                            ]);
                        }
                    } else {
                        Config::set('mode', $newMode);
                        $diff['mode'] = ['from' => $old, 'to' => $newMode];
                        if ($old === 'live') {
                            EventRecorder::event(EventRecorder::WARN, 'live_disabled', null, [
                                'to' => $newMode,
                            ]);
                        }
                    }
                }
            } else {
                $errors[] = "Недопустимый mode: {$newMode}";
            }
        }

        // ── Числовые настройки ──
        $numericKeys = [
            'delta_pct_of_amplitude', 'market_coef', 'paper_initial_deposit_usdt',
            'min_signal_target_pct', 'max_open_positions', 'max_total_orders_with_pending',
            'taker_fee_pct', 'signals_freshness_minutes',
            'qty_safety_margin_pct',
            'daily_drawdown.pct', 'signal_upper_cap.pct',
            'min_lot_overshoot.pct', 'long_short_balance.max_share_pct',
            'long_short_balance.min_total_to_check', 'funding_filter.extreme_pct',
        ];
        // Ключи с дополнительными ограничениями диапазона
        $rangeChecks = [
            'qty_safety_margin_pct' => ['min' => 0.0, 'max' => 50.0],
        ];
        foreach ($numericKeys as $key) {
            if (isset($body[$key]) && $body[$key] !== '') {
                $val = filter_var($body[$key], FILTER_VALIDATE_FLOAT);
                if ($val !== false) {
                    // Проверка диапазона, если задан
                    if (isset($rangeChecks[$key])) {
                        $min = $rangeChecks[$key]['min'];
                        $max = $rangeChecks[$key]['max'];
                        if ($val < $min || $val > $max) {
                            $errors[] = "Значение {$key} должно быть в диапазоне [{$min}, {$max}]";
                            continue;
                        }
                    }
                    $old = Config::get($key);
                    if ((string)$old !== (string)$val) {
                        Config::set($key, $val);
                        $diff[$key] = ['from' => $old, 'to' => $val];
                    }
                } else {
                    $errors[] = "Значение {$key} должно быть числом";
                }
            }
        }

        // ── Булевые настройки (через hidden+checkbox в twig: 0 или 1) ──
        // Чекбоксы в twig добавлены с предшествующим hidden value="0":
        // если checkbox снят — приходит '0', если отмечен — приходит '1'.
        $boolKeys = [
            'daily_drawdown.enabled', 'signal_upper_cap.enabled',
            'long_short_balance.enabled', 'funding_filter.enabled',
        ];
        foreach ($boolKeys as $key) {
            if (!isset($body[$key])) {
                // Поле отсутствует в POST вообще — пропустить (не изменять)
                continue;
            }
            // hidden+checkbox дают '0' или '1'
            $newVal = ((string)$body[$key] === '1') ? '1' : '0';
            // Читаем сырое значение из БД (без castValue), чтобы корректно
            // обработать legacy-значения 'true'/'false'/'' и однократно
            // нормализовать их в '0'/'1'.
            $rawOld = self::rawSettingValue($key);
            $oldNorm = in_array($rawOld, ['1', 'true', 1, true], true) ? '1' : '0';
            // Записываем при изменении ИЛИ при необходимости миграции legacy-формата.
            if ($oldNorm !== $newVal || !in_array($rawOld, ['0', '1'], true)) {
                Config::set($key, $newVal);
                $diff[$key] = ['from' => $rawOld, 'to' => $newVal];
            }
        }

        // ── Margin mode ──
        if (isset($body['bybit_margin_mode'])) {
            $mm = (string)$body['bybit_margin_mode'];
            if (in_array($mm, $allowedMarginModes, true)) {
                $old = (string)Config::get('bybit_margin_mode', null, 'cross');
                if ($old !== $mm) {
                    Config::set('bybit_margin_mode', $mm);
                    $diff['bybit_margin_mode'] = ['from' => $old, 'to' => $mm];
                }
            }
        }

        // ── Weights mode ──
        if (isset($body['weights_mode']) && $body['weights_mode'] !== '') {
            $wm  = (string)$body['weights_mode'];
            $old = (string)Config::get('weights_mode', null, 'equal');
            if ($old !== $wm) {
                Config::set('weights_mode', $wm);
                $diff['weights_mode'] = ['from' => $old, 'to' => $wm];
            }
        }

        // ── Стейблкоины ──
        if (isset($body['stablecoins'])) {
            $lines  = array_filter(array_map('trim', explode("\n", (string)$body['stablecoins'])));
            $newVal = implode(',', $lines);
            $old    = (string)Config::get('stablecoins_list', null, '');
            if ($old !== $newVal) {
                Config::set('stablecoins_list', $newVal);
                $diff['stablecoins_list'] = ['count' => count($lines)];
            }
        }

        // ── Стратегии ──
        $stratRows = Database::pdo()->query('SELECT id FROM strategies')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($stratRows as $sid) {
            // enabled: hidden+checkbox дают '0' или '1'; если поля нет в POST — не изменяем
            if (isset($body["strategy_{$sid}_enabled"])) {
                $newEnabled = ((string)$body["strategy_{$sid}_enabled"] === '1') ? 1 : 0;
                $oldRow = Database::pdo()->prepare('SELECT enabled FROM strategies WHERE id = :id');
                $oldRow->execute([':id' => $sid]);
                $oldEnabled = (int)$oldRow->fetchColumn();
                if ($oldEnabled !== $newEnabled) {
                    Database::pdo()->prepare(
                        'UPDATE strategies SET enabled = :e WHERE id = :id'
                    )->execute([':e' => $newEnabled, ':id' => $sid]);
                    $diff["strategy_{$sid}_enabled"] = ['from' => $oldEnabled, 'to' => $newEnabled];
                }
            }

            // weight (per-strategy)
            if (isset($body["strategy_{$sid}_weight"]) && $body["strategy_{$sid}_weight"] !== '') {
                $wVal = filter_var($body["strategy_{$sid}_weight"], FILTER_VALIDATE_FLOAT);
                if ($wVal !== false) {
                    Config::set('weight', $wVal, (string)$sid);
                }
            }

            // leverage_cap per strategy
            if (isset($body["strategy_{$sid}_leverage_cap"]) && $body["strategy_{$sid}_leverage_cap"] !== '') {
                $lcVal = filter_var($body["strategy_{$sid}_leverage_cap"], FILTER_VALIDATE_INT);
                if ($lcVal !== false) {
                    Config::set('leverage_cap', (int)$lcVal, (string)$sid);
                }
            }
        }

        // Записать событие
        if (!empty($diff)) {
            EventRecorder::event(EventRecorder::INFO, 'settings_updated', null, [
                'diff'   => $diff,
                'errors' => $errors,
            ]);
        }

        // Редирект обратно на settings с flash
        $redirectResp = $response
            ->withHeader('Location', '/settings' . (!empty($errors) ? '#live' : ''))
            ->withHeader('Set-Cookie', 'settings_saved=1; Path=/; Max-Age=5; HttpOnly; SameSite=Strict')
            ->withStatus(302);

        // v0.8.0: если были ошибки (например по mode=live) — выведём в live_flash.
        // ВАЖНО: используем withAddedHeader, иначе второй withHeader('Set-Cookie') заменит первый.
        if (!empty($errors)) {
            $msg = 'ERR|' . implode(' ', $errors);
            $redirectResp = $redirectResp->withAddedHeader(
                'Set-Cookie',
                'live_flash=' . rawurlencode($msg) . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict'
            );
        }

        return $redirectResp;
    }

    public function action(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body   = (array)$request->getParsedBody();
        $act    = (string)($body['action'] ?? '');
        $root   = defined('APP_ROOT') ? APP_ROOT : dirname(dirname(dirname(dirname(__DIR__))));

        switch ($act) {
            case 'paper-close-all':
                $this->execCliCommand($root, 'paper:close-all');
                break;
            case 'reconcile-now':
                $this->execCliCommand($root, 'reconcile:now');
                break;
            case 'deposit-refresh':
                $this->execCliCommand($root, 'deposit:refresh');
                break;
            default:
                Logger::get()->warning('SettingsController: неизвестное действие', ['action' => $act]);
                break;
        }

        $resp = $response->withHeader('Location', '/settings')->withStatus(302);
        return $resp;
    }

    /**
     * Прямое чтение строкового значения из БД (минуя Config::castValue).
     * Нужно, чтобы различать пустую строку, 'true'/'false', '0'/'1'.
     */
    private static function rawSettingValue(string $key): string
    {
        $stmt = Database::pdo()->prepare('SELECT value FROM settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        return $val === false ? '' : (string)$val;
    }

    private function execCliCommand(string $root, string $cmd): void
    {
        $php = PHP_BINARY;
        $cli = escapeshellarg("{$root}/bin/cli.php");
        $c   = escapeshellarg($cmd);
        $output = shell_exec("{$php} {$cli} {$c} 2>&1");
        Logger::get()->info("SettingsController: cli {$cmd}", ['output' => trim((string)$output)]);
    }
}
