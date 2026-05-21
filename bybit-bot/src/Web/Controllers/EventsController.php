<?php
declare(strict_types=1);

namespace BybitBot\Web\Controllers;

use BybitBot\Core\Config;
use BybitBot\Core\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * EventsController — вкладка «История событий» (v0.7.6).
 *
 * GET /events?source=global|trade&symbol=&level=&kind=&from=&to=&page=
 *
 * Источники:
 *   - global → таблица `events` (с опциональным symbol)
 *   - trade  → таблица `trade_events` JOIN trades (для symbol)
 *
 * Пагинация: 100 записей на страницу.
 */
final class EventsController
{
    private const PAGE_SIZE = 100;

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $twig   = Twig::fromRequest($request);
        $pdo    = Database::pdo();
        $params = $request->getQueryParams();

        // ── Параметры ──
        $source = isset($params['source']) && $params['source'] === 'trade' ? 'trade' : 'global';
        $symbol = isset($params['symbol']) ? trim((string)$params['symbol']) : '';
        $level  = isset($params['level'])  ? strtoupper(trim((string)$params['level']))  : '';
        $kind   = isset($params['kind'])   ? trim((string)$params['kind']) : '';
        $from   = isset($params['from'])   ? trim((string)$params['from']) : '';
        $to     = isset($params['to'])     ? trim((string)$params['to'])   : '';
        $page   = max(1, (int)($params['page'] ?? 1));

        // Валидация level
        $allowedLevels = ['', 'INFO', 'WARN', 'ERROR', 'CRITICAL'];
        if (!in_array($level, $allowedLevels, true)) $level = '';

        // Валидация дат (YYYY-MM-DD); из них делаем диапазон ISO-UTC.
        $fromIso = $this->dateToIsoFrom($from);
        $toIso   = $this->dateToIsoTo($to);

        // ── Сборка WHERE ──
        $where = [];
        $bind  = [];

        if ($source === 'global') {
            // events: id, ts, level, kind, symbol, payload_json
            if ($symbol !== '') {
                $where[] = "UPPER(e.symbol) LIKE :symbol";
                $bind[':symbol'] = '%' . strtoupper($symbol) . '%';
            }
        } else {
            // trade_events JOIN trades: символ из trades.symbol
            if ($symbol !== '') {
                $where[] = "UPPER(t.symbol) LIKE :symbol";
                $bind[':symbol'] = '%' . strtoupper($symbol) . '%';
            }
        }

        if ($level !== '') {
            $where[] = "e.level = :level";
            $bind[':level'] = $level;
        }
        if ($kind !== '') {
            $where[] = "e.kind = :kind";
            $bind[':kind'] = $kind;
        }
        if ($fromIso !== null) {
            $where[] = "e.ts >= :from";
            $bind[':from'] = $fromIso;
        }
        if ($toIso !== null) {
            $where[] = "e.ts <= :to";
            $bind[':to'] = $toIso;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // ── COUNT для пагинации ──
        if ($source === 'global') {
            $countSql = "SELECT COUNT(*) FROM events e $whereSql";
        } else {
            $countSql = "SELECT COUNT(*) FROM trade_events e
                         LEFT JOIN trades t ON t.id = e.trade_id
                         $whereSql";
        }
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $pages   = (int)max(1, (int)ceil($total / self::PAGE_SIZE));
        if ($page > $pages) $page = $pages;
        $offset  = ($page - 1) * self::PAGE_SIZE;

        // ── Основной запрос ──
        if ($source === 'global') {
            $sql = "SELECT e.id, e.ts, e.level, e.kind, e.symbol, e.payload_json, NULL AS trade_id
                    FROM events e
                    $whereSql
                    ORDER BY e.ts DESC, e.id DESC
                    LIMIT :limit OFFSET :offset";
        } else {
            $sql = "SELECT e.id, e.ts, e.level, e.kind, t.symbol AS symbol, e.payload_json, e.trade_id
                    FROM trade_events e
                    LEFT JOIN trades t ON t.id = e.trade_id
                    $whereSql
                    ORDER BY e.ts DESC, e.id DESC
                    LIMIT :limit OFFSET :offset";
        }
        $stmt = $pdo->prepare($sql);
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  self::PAGE_SIZE, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,         \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // ── Опции для dropdown kind: уникальные kind из соответствующей таблицы ──
        $kindsSql = $source === 'global'
            ? "SELECT DISTINCT kind FROM events ORDER BY kind"
            : "SELECT DISTINCT kind FROM trade_events ORDER BY kind";
        $kinds = $pdo->query($kindsSql)->fetchAll(\PDO::FETCH_COLUMN);

        return $twig->render($response, 'events.twig', [
            'mode'         => (string)Config::get('mode', null, 'paper'),
            'app_version'  => (string)Config::bootstrap('app.version', '0.7.0'),
            'rows'         => $rows,
            'source'       => $source,
            'filters'      => [
                'symbol' => $symbol,
                'level'  => $level,
                'kind'   => $kind,
                'from'   => $from,
                'to'     => $to,
            ],
            'kinds'        => $kinds,
            'levels'       => ['INFO', 'WARN', 'ERROR', 'CRITICAL'],
            'page'         => $page,
            'pages'        => $pages,
            'total'        => $total,
            'page_size'    => self::PAGE_SIZE,
        ]);
    }

    /**
     * 'YYYY-MM-DD' (MSK предполагается) → ISO-UTC начало дня (00:00 MSK = 21:00 UTC прошлого дня).
     * Для простоты считаем что ts хранится в UTC ISO8601. Здесь конвертируем границу так,
     * чтобы пользователь, выбирая «12 мая», получил все события 12 мая по своему времени.
     */
    private function dateToIsoFrom(string $d): ?string
    {
        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        // 00:00 MSK = -3h UTC. Делаем -3h от полуночи MSK.
        $dt = new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('Europe/Moscow'));
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
    }

    private function dateToIsoTo(string $d): ?string
    {
        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        // 23:59:59.999 MSK
        $dt = new \DateTimeImmutable($d . ' 23:59:59', new \DateTimeZone('Europe/Moscow'));
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.999\Z');
    }
}
