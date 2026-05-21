<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Config;
use BybitBot\Core\Logger;
use BybitBot\Exchange\AdapterFactory;
use BybitBot\Trade\ManualOrderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ManualController — Strategy 2: ручной ввод условных ордеров.
 *
 * POST /manual/submit       — принять форму, поставить conditional, redirect на /trades
 * GET  /manual/check-symbol — async-проверка символа на бирже (JSON)
 *
 * См. spec.md §15 (v0.6.0).
 */
final class ManualController
{
    /**
     * POST /manual/submit
     * body: symbol, side, entry, sl, tp
     */
    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();

        $input = [
            'symbol' => isset($body['symbol']) ? (string)$body['symbol'] : '',
            'side'   => isset($body['side'])   ? (string)$body['side']   : '',
            'entry'  => $body['entry'] ?? null,
            'sl'     => $body['sl']    ?? null,
            'tp'     => $body['tp']    ?? null,
            // v0.8.0.13: флажок «Не снимать по SL до открытия позиции» (RECOVERED-режим)
            'ignore_sl_until_open' => !empty($body['ignore_sl_until_open']) ? 1 : 0,
        ];

        // v0.9.0-step4c: мультиселект аккаунтов из формы s2 — fan-out открывает
        // независимые ордера в каждый выбранный аккаунт.
        $rawAccountIds = $body['account_ids'] ?? null;
        $accountIds    = [];
        if (is_array($rawAccountIds)) {
            foreach ($rawAccountIds as $aid) {
                $aid = (int)$aid;
                if ($aid > 0) { $accountIds[] = $aid; }
            }
            $accountIds = array_values(array_unique($accountIds));
        }

        $mode = (string)Config::get('mode', null, 'paper');

        Logger::get()->info('manual: submit received', array_merge($input, [
            'mode'        => $mode,
            'account_ids' => $accountIds,
        ]));

        // Сформируем список целевых аккаунтов для fan-out.
        // paper → [null]; testnet/live → выбранные + валидация принадлежности network + enabled.
        $targets = []; // [['id'=>?int,'name'=>?string], ...]
        $earlyError = null;

        if ($mode === 'paper') {
            $targets[] = ['id' => null, 'name' => null];
        } elseif ($mode === 'pause') {
            $earlyError = 'Режим pause: ручное открытие ордеров отключено';
        } else {
            // testnet/live: требуем явный выбор ≥ 1 аккаунта.
            if (empty($accountIds)) {
                $earlyError = 'Выберите хотя бы один аккаунт для открытия ордера';
            } else {
                // v0.9.0-step7 task5: ручное открытие = s2; аккаунт должен иметь s2_enabled=1.
                $enabled = BybitAccountsRepo::getEnabledForNetworkAndStrategy($mode, 's2');
                $enabledById = [];
                foreach ($enabled as $a) {
                    $enabledById[(int)$a['id']] = $a;
                }
                foreach ($accountIds as $aid) {
                    if (!isset($enabledById[$aid])) {
                        $earlyError = "Аккаунт #{$aid} не найден, выключен для стратегии s2 или не принадлежит network={$mode}";
                        break;
                    }
                    $targets[] = ['id' => $aid, 'name' => (string)$enabledById[$aid]['name']];
                }
            }
        }

        // Если ранняя ошибка — не вызываем submit() вообще.
        $results = [];
        if ($earlyError !== null) {
            $results[] = [
                'ok'      => false,
                'message' => $earlyError,
                'errors'  => ['_account' => $earlyError],
            ];
        } else {
            foreach ($targets as $t) {
                try {
                    $results[] = ManualOrderService::submit($input, $t['id'], $t['name']);
                } catch (\Throwable $e) {
                    Logger::get()->error('manual: submit threw', [
                        'error'        => $e->getMessage(),
                        'account_id'   => $t['id'],
                        'account_name' => $t['name'],
                    ]);
                    $accLabel = ($t['name'] !== null && $t['name'] !== '') ? " [{$t['name']}]" : '';
                    $results[] = [
                        'ok'           => false,
                        'message'      => "Внутренняя ошибка{$accLabel}: " . $e->getMessage(),
                        'account_id'   => $t['id'],
                        'account_name' => $t['name'],
                    ];
                }
            }
        }

        // Агрегация результатов для flash-сообщения.
        $okCount   = 0;
        $failCount = 0;
        $lines     = [];
        foreach ($results as $r) {
            if (!empty($r['ok'])) {
                $okCount++;
                $lines[] = (string)($r['message'] ?? '');
            } else {
                $failCount++;
                $line = (string)($r['message'] ?? 'Ошибка');
                if (!empty($r['errors'])) {
                    $errs = [];
                    foreach ($r['errors'] as $field => $err) {
                        $errs[] = ($field[0] === '_' ? '' : $field . ': ') . $err;
                    }
                    if (!empty($errs)) {
                        $line .= ' (' . implode('; ', $errs) . ')';
                    }
                }
                $lines[] = $line;
            }
        }

        $flash = [
            'ok'  => ($okCount > 0 && $failCount === 0),
            'msg' => count($lines) === 1
                ? $lines[0]
                : sprintf('Аккаунтов: %d (ok=%d, fail=%d). ', count($results), $okCount, $failCount) . implode(' | ', $lines),
        ];

        $cookieValue = rawurlencode(json_encode($flash, JSON_UNESCAPED_UNICODE));
        $cookie      = 'manual_flash=' . $cookieValue . '; Path=/; Max-Age=10; HttpOnly; SameSite=Strict';

        return $response
            ->withHeader('Location', '/trades')
            ->withHeader('Set-Cookie', $cookie)
            ->withStatus(302);
    }

    /**
     * GET /manual/check-symbol?symbol=MNT
     * Возвращает JSON {ok:bool, normalized:string, last_price?:float, error?:string}
     */
    public function checkSymbol(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $raw    = isset($params['symbol']) ? (string)$params['symbol'] : '';
        $sym    = ManualOrderService::normalizeSymbol($raw);

        $payload = ['symbol_input' => $raw, 'normalized' => $sym];

        if ($sym === '') {
            $payload['ok']    = false;
            $payload['error'] = 'empty';
            return $this->json($response, $payload, 400);
        }

        try {
            // v0.9.0-step4c: в multi-account testnet/live forCurrentMode() не работает
            // (нужен конкретный accountId). Для publicного endpoints берём первый enabled.
            $mode = (string)Config::get('mode', null, 'paper');
            $accountIdQuery = isset($params['account_id']) ? (int)$params['account_id'] : 0;
            $adapter = $this->pickAdapterForPublic($mode, $accountIdQuery);
            $info    = ManualOrderService::checkSymbol($sym, $adapter);

            if ($info === null) {
                $payload['ok']    = false;
                $payload['error'] = "Символ {$sym} не найден на бирже";
                return $this->json($response, $payload, 404);
            }

            $payload['ok']           = true;
            $payload['tick_size']    = $info['tickSize']    ?? null;
            $payload['qty_step']     = $info['qtyStep']     ?? null;
            $payload['qty_min']      = $info['qtyMin']      ?? null;
            $payload['max_leverage'] = $info['maxLeverage'] ?? null;

            // Попытка взять текущую цену через kline (1m, последняя свеча)
            try {
                $klines = $adapter->getKline($sym, '1', 1);
                if (!empty($klines)) {
                    $payload['last_price'] = (float)$klines[0]['close'];
                }
            } catch (\Throwable $e) {
                // не критично
            }

            return $this->json($response, $payload, 200);

        } catch (\Throwable $e) {
            Logger::get()->warning('manual: checkSymbol failed', [
                'symbol' => $sym,
                'error'  => $e->getMessage(),
            ]);
            $payload['ok']    = false;
            $payload['error'] = $e->getMessage();
            return $this->json($response, $payload, 500);
        }
    }

    private function json(ResponseInterface $response, array $data, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    /**
     * v0.9.0-step4c: выбрать адаптер для public-запросов (check-symbol).
     *   paper        → forCurrentMode().
     *   testnet/live → если передан $accountIdQuery>0 — forAccount($accountIdQuery);
     *                  иначе — первый enabled аккаунт network.
     *                  Если нет enabled — fallback на forCurrentMode() (легаси в этот
     *                  момент возможен только если ENV всё ещё хранит ключи).
     */
    private function pickAdapterForPublic(string $mode, int $accountIdQuery)
    {
        if ($mode === 'paper' || $mode === 'pause') {
            return AdapterFactory::forCurrentMode();
        }
        if ($accountIdQuery > 0) {
            try {
                return AdapterFactory::forAccount($accountIdQuery);
            } catch (\Throwable $e) {
                // хиппи-fallback: пробуем взять любой enabled.
            }
        }
        $enabled = BybitAccountsRepo::getEnabledForNetwork($mode);
        if (!empty($enabled)) {
            return AdapterFactory::forAccount((int)$enabled[0]['id']);
        }
        // Нет ни одного enabled — пытаемся legacy.
        return AdapterFactory::forCurrentMode();
    }
}
