<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Core\Config;
use BybitBot\Core\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * SignalsController — вкладка «Журнал сигналов» (v0.7.7).
 *
 * GET /signals?symbol=&decision=&from=&to=&page=
 *
 * Retention 24ч: применяется в Importer перед каждым импортом.
 * Источник данных: таблица `signals` (стратегия S1, signalsHourly.json).
 *
 * Пагинация: 100 записей на страницу.
 */
final class SignalsController
{
    private const PAGE_SIZE = 100;

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $twig   = Twig::fromRequest($request);
        $pdo    = Database::pdo();
        $params = $request->getQueryParams();

        $symbol   = isset($params['symbol'])   ? trim((string)$params['symbol'])   : '';
        $decision = isset($params['decision']) ? trim((string)$params['decision']) : '';
        $from     = isset($params['from'])     ? trim((string)$params['from'])     : '';
        $to       = isset($params['to'])       ? trim((string)$params['to'])       : '';
        $page     = max(1, (int)($params['page'] ?? 1));

        $allowedDecisions = [
            '',
            'accepted',
            'rejected_unresolved',
            'rejected_filter',
            'rejected_guard',
            'rejected_duplicate',
            'rejected_other',
            'pending',  // спецзначение: decision IS NULL
        ];
        if (!in_array($decision, $allowedDecisions, true)) $decision = '';

        $fromIso = $this->dateToIsoFrom($from);
        $toIso   = $this->dateToIsoTo($to);

        $where = [];
        $bind  = [];

        if ($symbol !== '') {
            $where[] = "(UPPER(symbol) LIKE :symbol OR UPPER(bybit_symbol) LIKE :symbol)";
            $bind[':symbol'] = '%' . strtoupper($symbol) . '%';
        }

        if ($decision === 'pending') {
            $where[] = "decision IS NULL";
        } elseif ($decision !== '') {
            $where[] = "decision = :decision";
            $bind[':decision'] = $decision;
        }

        if ($fromIso !== null) {
            $where[] = "saved_at_utc >= :from";
            $bind[':from'] = $fromIso;
        }
        if ($toIso !== null) {
            $where[] = "saved_at_utc <= :to";
            $bind[':to'] = $toIso;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM signals $whereSql");
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $pages  = (int)max(1, (int)ceil($total / self::PAGE_SIZE));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * self::PAGE_SIZE;

        $sql = "SELECT id, saved_at_utc, symbol, bybit_symbol, side, target, signal_type,
                       rsi, w7, w14, w30, w_all, potential,
                       resolution_status, decision, decision_reason, decision_at, trade_id,
                       imported_at
                FROM signals
                $whereSql
                ORDER BY saved_at_utc DESC, id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  self::PAGE_SIZE, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,         \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Сводка решений (для счётчиков под фильтром)
        $summary = $pdo->query(
            "SELECT COALESCE(decision, 'pending') AS d, COUNT(*) AS c
             FROM signals
             GROUP BY decision"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        return $twig->render($response, 'signals.twig', [
            'mode'        => (string)Config::get('mode', null, 'paper'),
            'app_version' => (string)Config::bootstrap('app.version', '0.7.0'),
            'rows'        => $rows,
            'filters'     => [
                'symbol'   => $symbol,
                'decision' => $decision,
                'from'     => $from,
                'to'       => $to,
            ],
            'page'        => $page,
            'pages'       => $pages,
            'total'       => $total,
            'page_size'   => self::PAGE_SIZE,
            'summary'     => $summary,
        ]);
    }

    private function dateToIsoFrom(string $d): ?string
    {
        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        $dt = new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('Europe/Moscow'));
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function dateToIsoTo(string $d): ?string
    {
        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        $dt = new \DateTimeImmutable($d . ' 23:59:59', new \DateTimeZone('Europe/Moscow'));
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
