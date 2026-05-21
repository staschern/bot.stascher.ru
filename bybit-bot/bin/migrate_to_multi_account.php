<?php
/**
 * v0.9.0 — бэккомпат-миграция данных для multi-account.
 *
 * Идемпотентен: повторный запуск ничего не ломает.
 *
 * Что делает:
 *   1. Применяет миграции (вдруг 014 ещё не накатан).
 *   2. Если в bybit_accounts ещё нет ни одной записи — создаёт default-аккаунт:
 *        - name='main', network='live', enabled=1
 *      (используя last4-маску из существующего data/secrets/live_keys.php,
 *      если он есть).
 *   3. Переносит data/secrets/live_keys.php → data/secrets/account_{id}.php
 *      (через копию, права 0600). Старый файл НЕ удаляется автоматически —
 *      это сделает шаг 2 релиза вместе с переключением SecretsService.
 *   4. Проставляет account_id и account_name всем существующим
 *      trades/orders/positions, у которых сейчас account_id IS NULL
 *      И (mode='live' для trades / по связи для orders/positions).
 *      Paper-трейды остаются с account_id=NULL — это норма.
 *
 * Запуск (один раз после deploy v0.9.0):
 *   php bin/migrate_to_multi_account.php
 *
 * Опции:
 *   --dry-run   только показать, что будет сделано
 *   --apply     применить (по умолчанию)
 *   --name=X    имя default-аккаунта (по умолчанию 'main')
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BybitBot\Core\Bootstrap;
use BybitBot\Core\Config;
use BybitBot\Core\Database;
use BybitBot\Core\EventRecorder;
use BybitBot\Core\BybitAccountsRepo;
use BybitBot\Core\Migrator;

Bootstrap::init(dirname(__DIR__));

// ---- argv ----
$dryRun = in_array('--dry-run', $argv, true);
$defaultName = 'main';
foreach ($argv as $a) {
    if (strpos($a, '--name=') === 0) {
        $defaultName = trim(substr($a, 7));
    }
}
if ($defaultName === '') $defaultName = 'main';

echo "=== v0.9.0 multi-account data migration ===\n";
echo 'Mode: ' . ($dryRun ? 'DRY-RUN (без изменений)' : 'APPLY') . "\n";
echo "Default account name: {$defaultName}\n\n";

$pdo = Database::pdo();

// ---- 1) Накатываем миграции ----
echo "1) Накатываю миграции (если есть неприменённые)...\n";
$migrator = new Migrator((string)Config::bootstrap('paths.migrations', dirname(__DIR__) . '/data/migrations'));
$applied = $migrator->migrate();
if (count($applied) > 0) {
    echo '   применены: ' . implode(', ', $applied) . "\n";
} else {
    echo "   все миграции уже применены\n";
}

// ---- 2) default account ----
echo "\n2) Проверяю наличие default-аккаунта в bybit_accounts...\n";
$existing = BybitAccountsRepo::listAll(true);
$defaultId = null;
if (count($existing) > 0) {
    $defaultId = (int)$existing[0]['id'];
    echo "   default уже есть: id={$defaultId} name='{$existing[0]['name']}' (всего записей: " . count($existing) . ")\n";
} else {
    // Маска из старого live_keys.php (если файл существует).
    $liveKeysPath = (string)Config::bootstrap('paths.secrets', dirname(__DIR__) . '/data/secrets') . '/live_keys.php';
    $maskApiKey = '';
    if (is_file($liveKeysPath)) {
        $data = @require $liveKeysPath;
        if (is_array($data) && isset($data['api_key'])) {
            $maskApiKey = (string)$data['api_key'];
        }
    }

    if ($dryRun) {
        echo "   [dry-run] СОЗДАЛ БЫ: name='{$defaultName}' network='live' enabled=1 mask='" . maskPreview($maskApiKey) . "'\n";
        $defaultId = -1;
    } else {
        $defaultId = BybitAccountsRepo::create($defaultName, 'live', $maskApiKey, true);
        echo "   создан default аккаунт: id={$defaultId} name='{$defaultName}' mask='" . maskPreview($maskApiKey) . "'\n";
        try {
            EventRecorder::event(EventRecorder::INFO, 'bybit_account_created', [
                'account_id' => $defaultId,
                'name'       => $defaultName,
                'reason'     => 'v0.9.0 backfill default',
            ]);
        } catch (\Throwable $e) { /* not fatal */ }
    }
}

// ---- 3) Копия live_keys.php → account_{id}.php ----
echo "\n3) Копирую секрет-файл live_keys.php → account_{$defaultId}.php (если есть исходник)...\n";
$secretsDir = (string)Config::bootstrap('paths.secrets', dirname(__DIR__) . '/data/secrets');
$src = $secretsDir . '/live_keys.php';
$dst = $secretsDir . "/account_{$defaultId}.php";

if (!is_file($src)) {
    echo "   live_keys.php нет — пропускаю (видимо, live ещё не настроен)\n";
} elseif (is_file($dst)) {
    echo "   account_{$defaultId}.php уже существует — пропускаю (идемпотентно)\n";
} elseif ($dryRun) {
    echo "   [dry-run] СКОПИРОВАЛ БЫ {$src} → {$dst} (chmod 0600)\n";
} else {
    if (!@copy($src, $dst)) {
        fwrite(STDERR, "   ОШИБКА: не удалось скопировать {$src} → {$dst}\n");
        exit(2);
    }
    @chmod($dst, 0600);
    echo "   создан {$dst} (chmod 0600)\n";
    echo "   ВНИМАНИЕ: старый live_keys.php НЕ удалён. Удалите его вручную после проверки.\n";
}

// ---- 4) Backfill account_id / account_name ----
echo "\n4) Заполняю account_id/account_name у существующих trades/orders/positions...\n";

if ($defaultId === -1) {
    echo "   [dry-run] пропускаю backfill (id ещё не создан)\n";
} else {
    // trades: только mode='live' + account_id IS NULL
    $countSel = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE account_id IS NULL AND mode = 'live'");
    $countSel->execute();
    $tradesToBackfill = (int)$countSel->fetchColumn();
    echo "   trades (mode='live', account_id IS NULL): {$tradesToBackfill} строк\n";

    if (!$dryRun && $tradesToBackfill > 0) {
        $upd = $pdo->prepare(
            "UPDATE trades
               SET account_id = :aid, account_name = :name
             WHERE account_id IS NULL AND mode = 'live'"
        );
        $upd->execute([':aid' => $defaultId, ':name' => $defaultName]);
        echo "   обновлено trades: " . $upd->rowCount() . "\n";
    }

    // orders: по связи с trades.account_id (берём через trade_id)
    $ordersToBackfill = (int)$pdo->query(
        "SELECT COUNT(*) FROM orders o
         JOIN trades t ON t.id = o.trade_id
         WHERE o.account_id IS NULL AND t.account_id IS NOT NULL"
    )->fetchColumn();
    echo "   orders (account_id IS NULL, связан с trade у которого есть account_id): {$ordersToBackfill}\n";

    if (!$dryRun && $ordersToBackfill > 0) {
        $upd = $pdo->prepare(
            "UPDATE orders
                SET account_id = (
                    SELECT t.account_id FROM trades t WHERE t.id = orders.trade_id
                )
              WHERE account_id IS NULL
                AND trade_id IN (SELECT id FROM trades WHERE account_id IS NOT NULL)"
        );
        $upd->execute();
        echo "   обновлено orders: " . $upd->rowCount() . "\n";
    }

    // positions: аналогично через trade_id (если поле есть; positions схема может различаться)
    $posHasTradeId = false;
    try {
        $cols = $pdo->query("PRAGMA table_info(positions)")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            if ($c['name'] === 'trade_id') { $posHasTradeId = true; break; }
        }
    } catch (\Throwable $e) {}

    if ($posHasTradeId) {
        $posToBackfill = (int)$pdo->query(
            "SELECT COUNT(*) FROM positions p
             JOIN trades t ON t.id = p.trade_id
             WHERE p.account_id IS NULL AND t.account_id IS NOT NULL"
        )->fetchColumn();
        echo "   positions (account_id IS NULL, есть trade.account_id): {$posToBackfill}\n";

        if (!$dryRun && $posToBackfill > 0) {
            $upd = $pdo->prepare(
                "UPDATE positions
                    SET account_id = (
                        SELECT t.account_id FROM trades t WHERE t.id = positions.trade_id
                    )
                  WHERE account_id IS NULL
                    AND trade_id IN (SELECT id FROM trades WHERE account_id IS NOT NULL)"
            );
            $upd->execute();
            echo "   обновлено positions: " . $upd->rowCount() . "\n";
        }
    } else {
        echo "   positions: нет колонки trade_id — пропускаю backfill\n";
    }
}

echo "\n=== ГОТОВО ===\n";
if ($dryRun) {
    echo "Это был DRY-RUN. Чтобы применить, запустите без --dry-run.\n";
} else {
    echo "Multi-account миграция применена. Default аккаунт: id={$defaultId} name='{$defaultName}'.\n";
    echo "Следующий шаг релиза: SecretsService::forAccount() и AdapterFactory::forAccount().\n";
}

function maskPreview(string $key): string
{
    $len = strlen($key);
    if ($len === 0) return '(пусто)';
    if ($len <= 4) return str_repeat('*', $len);
    return str_repeat('*', max(0, $len - 4)) . substr($key, -4);
}
