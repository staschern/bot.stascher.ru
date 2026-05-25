<?php
declare(strict_types=1);
namespace BybitBot\Web\Controllers;

use BybitBot\Core\Database;
use BybitBot\Exchange\AdapterFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ManagementController
{
    /** POST /trades/{id}/manage/partial-close  body: {action:'market'|'limit', pct:float, tp_price?:float} */
    public function partialClose(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $tradeId = (int)($args['id'] ?? 0);
        $body = $req->getParsedBody() ?: [];

        // Support JSON body
        if (empty($body)) {
            $rawBody = (string)$req->getBody();
            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        $action  = (string)($body['action'] ?? 'market');
        $pct     = isset($body['pct']) ? (float)$body['pct'] : 0.0;
        $tpPrice = isset($body['tp_price']) && $body['tp_price'] !== '' ? (float)$body['tp_price'] : null;

        if ($tradeId <= 0 || $pct <= 0 || $pct > 100) {
            return $this->json($res, ['ok' => false, 'error' => 'Invalid params'], 400);
        }

        $trade = $this->loadOpenTrade($tradeId);
        if ($trade === null) {
            return $this->json($res, ['ok' => false, 'error' => 'Trade not found or not open'], 404);
        }
        if ($trade['mode'] === 'paper') {
            return $this->json($res, ['ok' => false, 'error' => 'Not available in paper mode'], 400);
        }

        try {
            $adapter = $this->getAdapter($trade);
            if ($action === 'limit') {
                if ($tpPrice === null || $tpPrice <= 0) {
                    return $this->json($res, ['ok' => false, 'error' => 'tp_price required for limit'], 400);
                }
                $result = $adapter->partialCloseLimit($tradeId, $pct, $tpPrice);
            } else {
                $result = $adapter->partialCloseMarket($tradeId, $pct);
            }
            return $this->json($res, $result);
        } catch (\Throwable $e) {
            return $this->json($res, ['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /trades/{id}/manage/set-stops  body: {sl?:float, tp?:float} */
    public function setStops(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $tradeId = (int)($args['id'] ?? 0);
        $body = $req->getParsedBody() ?: [];

        // Support JSON body
        if (empty($body)) {
            $rawBody = (string)$req->getBody();
            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        $sl = isset($body['sl']) && $body['sl'] !== '' ? (float)$body['sl'] : null;
        $tp = isset($body['tp']) && $body['tp'] !== '' ? (float)$body['tp'] : null;

        if ($tradeId <= 0) {
            return $this->json($res, ['ok' => false, 'error' => 'Invalid trade id'], 400);
        }
        if ($sl === null && $tp === null) {
            return $this->json($res, ['ok' => false, 'error' => 'Specify at least SL or TP'], 400);
        }

        $trade = $this->loadOpenTrade($tradeId);
        if ($trade === null) {
            return $this->json($res, ['ok' => false, 'error' => 'Trade not found or not open'], 404);
        }
        if ($trade['mode'] === 'paper') {
            return $this->json($res, ['ok' => false, 'error' => 'Not available in paper mode'], 400);
        }

        try {
            $adapter = $this->getAdapter($trade);
            $result = $adapter->setManualStops($tradeId, $sl, $tp);
            return $this->json($res, $result);
        } catch (\Throwable $e) {
            return $this->json($res, ['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function loadOpenTrade(int $id): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT * FROM trades WHERE id = :id AND status IN ('OPEN','AVERAGED') LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function getAdapter(array $trade)
    {
        $accId = isset($trade['account_id']) && $trade['account_id'] !== null ? (int)$trade['account_id'] : null;
        if ($accId !== null) {
            return AdapterFactory::forAccount($accId);
        }
        return AdapterFactory::forExchange((string)$trade['mode']);
    }

    private function json(ResponseInterface $res, array $data, int $status = 200): ResponseInterface
    {
        $res->getBody()->write(json_encode($data));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
