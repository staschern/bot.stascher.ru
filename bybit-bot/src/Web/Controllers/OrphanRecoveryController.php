<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\Logger;
use BybitBot\Exchange\AdapterFactory;
use BybitBot\Strategies\StrategyRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Восстановление позиций, открытых на Bybit но не отслеживаемых ботом.
 *
 * GET  /orphans/scan?account_id=N  — сравнить Bybit-позиции с активными трейдами
 * POST /orphans/recover            — создать trade+position записи и вызвать onPositionOpened
 */
final class OrphanRecoveryController
{
    /**
     * GET /orphans/scan?account_id=N
     *
     * Returns Bybit open positions for the account, each flagged as:
     *  - conflict: "Trade #NNN" if bot already tracks that symbol+side
     *  - otherwise: orphan, ready to recover
     */
    public function scan(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params    = $request->getQueryParams();
        $accountId = isset($params['account_id']) ? (int)$params['account_id'] : 0;

        if ($accountId <= 0) {
            return $this->json($response, ['ok' => false, 'error' => 'account_id required'], 400);
        }

        $account = BybitAccountsRepo::find($accountId);
        if (!$account) {
            return $this->json($response, ['ok' => false, 'error' => 'account_not_found'], 404);
        }

        try {
            $adapter        = AdapterFactory::forAccount($accountId);
            $bybitPositions = $adapter->getPositions();
        } catch (\Throwable $e) {
            return $this->json($response, ['ok' => false, 'error' => 'bybit_error: ' . $e->getMessage()], 502);
        }

        $pdo = Database::pdo();

        // Build lookup: "SYMBOL_BuySell" → trade_id for active bot trades on this account
        $stmt = $pdo->prepare(
            "SELECT id, symbol, side FROM trades
             WHERE account_id = :acc AND status IN ('OPEN','AVERAGED') AND closed_at IS NULL"
        );
        $stmt->execute([':acc' => $accountId]);
        $activeKey = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $t) {
            $bySide = ((string)$t['side'] === 'long') ? 'Buy' : 'Sell';
            $activeKey[(string)$t['symbol'] . '_' . $bySide] = (int)$t['id'];
        }

        $positions = [];
        foreach ($bybitPositions as $pos) {
            $size = (float)($pos['size'] ?? 0);
            if ($size <= 0) continue;

            $symbol    = (string)($pos['symbol'] ?? '');
            $bybitSide = (string)($pos['side']   ?? '');
            if (!$symbol || !$bybitSide) continue;

            $key      = $symbol . '_' . $bybitSide;
            $conflict = isset($activeKey[$key]) ? ('Trade #' . $activeKey[$key]) : null;

            $slStr = (string)($pos['stopLoss']   ?? '');
            $tpStr = (string)($pos['takeProfit'] ?? '');

            $positions[] = [
                'symbol'         => $symbol,
                'side'           => $bybitSide === 'Buy' ? 'long' : 'short',
                'bybit_side'     => $bybitSide,
                'qty'            => $size,
                'entry_price'    => (float)($pos['avgPrice']       ?? 0),
                'mark_price'     => (float)($pos['markPrice']      ?? $pos['lastPrice'] ?? 0),
                'unrealised_pnl' => (float)($pos['unrealisedPnl']  ?? 0),
                'sl_price'       => ($slStr !== '' && $slStr !== '0') ? (float)$slStr : null,
                'tp_price'       => ($tpStr !== '' && $tpStr !== '0') ? (float)$tpStr : null,
                'leverage'       => (int)($pos['leverage']         ?? 1),
                'conflict'       => $conflict,
            ];
        }

        return $this->json($response, [
            'ok'           => true,
            'account_id'   => $accountId,
            'account_name' => (string)$account['name'],
            'positions'    => $positions,
        ]);
    }

    /**
     * POST /orphans/recover
     *
     * Body (JSON):
     *   { account_id: int, positions: [{symbol, side, qty, entry_price, leverage, sl_price, tp_price?}] }
     *
     * For each position:
     *  1. Inserts trade (OPEN, s2) + positions record in a transaction
     *  2. Immediately calls Strategy2::onPositionOpened → sets SL_real, trailing, avg order
     *     (if it fails, the cron retries next minute since trailing_trigger stays NULL)
     */
    public function recover(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw       = (string)$request->getBody();
        $body      = (array)(json_decode($raw, true) ?? []);
        $accountId = isset($body['account_id']) ? (int)$body['account_id'] : 0;
        $positions = (isset($body['positions']) && is_array($body['positions'])) ? $body['positions'] : [];

        if ($accountId <= 0 || empty($positions)) {
            return $this->json($response, ['ok' => false, 'error' => 'account_id and positions required'], 400);
        }

        $account = BybitAccountsRepo::find($accountId);
        if (!$account) {
            return $this->json($response, ['ok' => false, 'error' => 'account_not_found'], 404);
        }

        $exchange    = (string)$account['network']; // 'live'|'testnet'
        $accountName = (string)$account['name'];
        $marketCoef  = (float)Config::get('market_coef', null, 1.35);
        $marginMode  = (string)Config::get('margin_mode',  null, 'cross');
        $pdo         = Database::pdo();

        try {
            $adapter = AdapterFactory::forAccount($accountId);
        } catch (\Throwable $e) {
            return $this->json($response, ['ok' => false, 'error' => 'adapter_error: ' . $e->getMessage()], 502);
        }

        $strategiesConfig = require dirname(__DIR__, 3) . '/config/strategies.php';
        $registry = new StrategyRegistry($strategiesConfig);
        $strategy = $registry->get('s2');

        $context = [
            'adapter'        => $adapter,
            'deposit_anchor' => 0.0,
            'mode'           => $exchange,
        ];

        $results = [];

        foreach ($positions as $pos) {
            $symbol     = trim((string)($pos['symbol']      ?? ''));
            $side       = trim((string)($pos['side']        ?? '')); // 'long'|'short'
            $qty        = (float)($pos['qty']               ?? 0);
            $entryPrice = (float)($pos['entry_price']       ?? 0);
            $leverage   = max(1, (int)($pos['leverage']     ?? 1));
            $slRaw      = ($pos['sl_price'] !== null && $pos['sl_price'] !== '') ? (float)$pos['sl_price'] : null;
            $tpRaw      = ($pos['tp_price'] !== null && $pos['tp_price'] !== '') ? (float)$pos['tp_price'] : null;

            if (!$symbol || !in_array($side, ['long', 'short'], true)
                || $qty <= 0 || $entryPrice <= 0 || $slRaw === null || $slRaw <= 0) {
                $results[] = ['symbol' => $symbol ?: '?', 'ok' => false, 'error' => 'invalid_params'];
                continue;
            }

            // Derive p_for_strategy_calc from entered SL (reverse of Strategy1 SL_real formula:
            //   slReal = entry - entry * sign * p * 2 * marketCoef / 100  →  p = |entry - sl| / entry * 100 / (2*mc))
            $pCalc = abs($entryPrice - $slRaw) / $entryPrice * 100.0 / (2.0 * $marketCoef);

            // Auto-calculate TP if not given: symmetric to SL (1:1 R:R)
            $sign    = ($side === 'long') ? 1.0 : -1.0;
            $tpPrice = ($tpRaw !== null && $tpRaw > 0)
                ? $tpRaw
                : $entryPrice + $sign * abs($entryPrice - $slRaw);

            $bybitSide = ($side === 'long') ? 'Buy' : 'Sell';
            $now       = gmdate('Y-m-d\\TH:i:s.v\\Z');

            // ── 1. Insert trade + positions (transaction) ──
            $tradeId = null;
            try {
                $pdo->beginTransaction();

                $pdo->prepare(
                    "INSERT INTO trades
                     (mode, strategy_id, symbol, side, status,
                      entry_ref, entry_real, qty_current, qty_initial,
                      leverage, margin_mode, tp_init, sl_init, sl_current,
                      p_for_strategy_calc, opened_at, created_at,
                      account_id, account_name)
                     VALUES
                     (:mode, 's2', :sym, :side, 'OPEN',
                      :entry, :entry, :qty, :qty,
                      :lev, :mm, :tp, :sl, :sl,
                      :p, :now, :now,
                      :accid, :accname)"
                )->execute([
                    ':mode'    => $exchange,
                    ':sym'     => $symbol,
                    ':side'    => $side,
                    ':entry'   => $entryPrice,
                    ':qty'     => $qty,
                    ':lev'     => $leverage,
                    ':mm'      => $marginMode,
                    ':tp'      => $tpPrice,
                    ':sl'      => $slRaw,
                    ':p'       => $pCalc,
                    ':now'     => $now,
                    ':accid'   => $accountId,
                    ':accname' => $accountName,
                ]);
                $tradeId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    "INSERT INTO positions
                     (trade_id, symbol, side, qty, qty_initial, avg_entry_price,
                      leverage, margin_mode, opened_at, exchange, sl_price, tp_price, account_id)
                     VALUES
                     (:tid, :sym, :bside, :qty, :qty, :entry,
                      :lev, :mm, :now, :exch, :sl, :tp, :accid)"
                )->execute([
                    ':tid'   => $tradeId,
                    ':sym'   => $symbol,
                    ':bside' => $bybitSide,
                    ':qty'   => $qty,
                    ':entry' => $entryPrice,
                    ':lev'   => $leverage,
                    ':mm'    => $marginMode,
                    ':now'   => $now,
                    ':exch'  => $exchange,
                    ':sl'    => $slRaw,
                    ':tp'    => $tpPrice,
                    ':accid' => $accountId,
                ]);

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                Logger::get()->error('orphan_recovery: DB insert failed', [
                    'symbol' => $symbol, 'error' => $e->getMessage(),
                ]);
                $results[] = ['symbol' => $symbol, 'ok' => false, 'error' => 'db_error: ' . $e->getMessage()];
                continue;
            }

            // ── 2. Immediately call onPositionOpened (API calls; cron retries on failure) ──
            $setupStatus = 'ok';
            $setupError  = null;
            try {
                $trade = [
                    'id'                  => $tradeId,
                    'symbol'              => $symbol,
                    'side'                => $side,
                    'entry_real'          => $entryPrice,
                    'p_for_strategy_calc' => $pCalc,
                    'qty_current'         => $qty,
                    'qty_initial'         => $qty,
                    'account_id'          => $accountId,
                ];
                $strategy->onPositionOpened($trade, $context);

                EventRecorder::tradeEvent($tradeId, EventRecorder::INFO, 'orphan_recovered', [
                    'exchange'    => $exchange,
                    'symbol'      => $symbol,
                    'side'        => $side,
                    'qty'         => $qty,
                    'entry_price' => $entryPrice,
                    'sl_price'    => $slRaw,
                    'p_calc'      => round($pCalc, 4),
                ]);
            } catch (\Throwable $e) {
                // Trade saved; cron_minute will retry onPositionOpened next minute
                // (detects OPEN + trailing_trigger IS NULL + entry_real IS NOT NULL)
                $setupStatus = 'deferred';
                $setupError  = $e->getMessage();
                Logger::get()->warning('orphan_recovery: onPositionOpened deferred', [
                    'trade_id' => $tradeId, 'symbol' => $symbol, 'error' => $e->getMessage(),
                ]);
            }

            $results[] = [
                'symbol'   => $symbol,
                'ok'       => true,
                'trade_id' => $tradeId,
                'setup'    => $setupStatus,
                'error'    => $setupError,
            ];
        }

        $allOk = true;
        foreach ($results as $r) {
            if (!$r['ok']) { $allOk = false; break; }
        }

        return $this->json($response, ['ok' => $allOk, 'results' => $results]);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
