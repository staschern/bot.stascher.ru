<?php
declare(strict_types=1);

namespace BybitBot\Web;

use BybitBot\Core\Config;
use BybitBot\Web\Controllers\AuthController;
use BybitBot\Web\Controllers\BybitAccountsController;
use BybitBot\Web\Controllers\DashboardController;
use BybitBot\Web\Controllers\EventsController;
use BybitBot\Web\Controllers\HealthController;
use BybitBot\Web\Controllers\SignalsController;
use BybitBot\Web\Controllers\ManualController;
use BybitBot\Web\Controllers\SettingsController;
use BybitBot\Web\Controllers\StatsController;
use BybitBot\Web\Controllers\TradesController;
use BybitBot\Web\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Сборка Slim-приложения с Twig и роутами.
 *
 * Stage 3: добавлены маршруты /settings и /trades.
 *
 * См. spec.md §14 (v0.5.0).
 */
final class AppFactory
{
    public static function create(): App
    {
        $app = SlimAppFactory::create();

        $app->addRoutingMiddleware();

        $debug = (bool)Config::bootstrap('app.debug', false);
        $app->addErrorMiddleware($debug, true, true);
        // v0.9.0-step7: пишем uncaught throwables в logs/slim_errors.log — полезно для отладки на проде.
        $__logDir = (string)Config::bootstrap('paths.root', dirname(__DIR__, 2)) . '/logs';
        if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
        $__logFile = $__logDir . '/slim_errors.log';
        set_exception_handler(function (\Throwable $e) use ($__logFile) {
            @file_put_contents(
                $__logFile,
                '[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n"
                    . $e->getTraceAsString() . "\n\n",
                FILE_APPEND
            );
        });

        // Twig
        $twig = Twig::create(
            (string)Config::bootstrap('paths.templates'),
            [
                'cache'       => false,
                'debug'       => $debug,
                'auto_reload' => true,
            ],
        );

        // Фильтр |msk — конвертирует UTC ISO-время в Europe/Moscow.
        // Синтаксис: {{ ts|msk }}                  → "2026-05-10 17:01"
        //          {{ ts|msk('Y-m-d H:i:s') }}    → с секундами
        // Источник по формату: ISO-8601 (Z) или 'YYYY-MM-DD HH:MM:SS' (считаем UTC).
        $twig->getEnvironment()->addFilter(
            new \Twig\TwigFilter('msk', static function ($value, string $format = 'Y-m-d H:i') {
                if ($value === null || $value === '' || $value === '—') {
                    return '—';
                }
                try {
                    $dt = new \DateTimeImmutable((string)$value, new \DateTimeZone('UTC'));
                    return $dt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format($format);
                } catch (\Throwable $e) {
                    return (string)$value;
                }
            })
        );

        // v0.9.0-step10: русский формат чисел в шаблонах.
        // Дефолты Twig: десятичный = '.', тысячи = ','.
        // Меняем на ',' для десятичного и '' (пусто) для тысяч —
        // итог: 1000000.555555 → 1000000,555555.
        // Срабатывает для всех |number_format(N) без явных сепараторов.
        try {
            /** @var \Twig\Extension\CoreExtension $core */
            $core = $twig->getEnvironment()->getExtension(\Twig\Extension\CoreExtension::class);
            $core->setNumberFormat(2, ',', '');
        } catch (\Throwable $e) {
            // не критично — продолжаем с дефолтным форматом.
        }

        $app->add(TwigMiddleware::create($app, $twig));

        // ───── публичные роуты ─────
        $app->get ('/login',   [AuthController::class, 'showLogin'])->setName('login');
        $app->post('/login',   [AuthController::class, 'doLogin']);
        $app->get ('/logout',  [AuthController::class, 'logout'])->setName('logout');
        $app->get ('/healthz', [HealthController::class, 'index'])->setName('healthz');

        // ───── защищённые роуты ─────
        $app->group('', function ($group) {
            $group->get('/',          [DashboardController::class, 'index'])->setName('home');
            $group->get('/dashboard', [DashboardController::class, 'index'])->setName('dashboard');

            // Stats (v0.7.4)
            $group->get('/stats', [StatsController::class, 'index'])->setName('stats');
            // v0.9.0-step9 task4: backfill equity_snapshots
            $group->post('/stats/backfill-snapshots', [StatsController::class, 'backfillSnapshots'])
                  ->setName('stats_backfill_snapshots');
            // v0.9.1: ручной ввод начального баланса и обновление снапшота текущего периода
            $group->post('/stats/set-deposit',             [StatsController::class, 'setDeposit'])->setName('stats_set_deposit');
            $group->post('/stats/save-current-snapshots',  [StatsController::class, 'saveCurrentSnapshots'])->setName('stats_save_current_snapshots');

            // Events history (v0.7.6)
            $group->get('/events', [EventsController::class, 'index'])->setName('events');

            // Signals log (v0.7.7)
            $group->get('/signals', [SignalsController::class, 'index'])->setName('signals');

            // Trades
            $group->get('/trades',      [TradesController::class, 'index'])->setName('trades');
            $group->get('/trades/{id}', [TradesController::class, 'detail'])->setName('trade_detail');
            $group->post('/trades/{id}/label', [TradesController::class, 'setLabel'])->setName('trade_set_label');
            $group->post('/trades/{id}/close', [TradesController::class, 'manualClose'])->setName('trade_manual_close');
            // v0.8.0.12: восстановление отменённого conditional
            $group->post('/trades/{id}/restore', [TradesController::class, 'restore'])->setName('trade_restore');
            // v0.8.0.13: принудительный запуск cron_minute из UI
            $group->post('/admin/cron-minute/run', [TradesController::class, 'runCronMinute'])->setName('cron_minute_run');

            // Manual entry (Strategy 2)
            $group->post('/manual/submit',       [ManualController::class, 'submit'])->setName('manual_submit');
            $group->get ('/manual/check-symbol', [ManualController::class, 'checkSymbol'])->setName('manual_check_symbol');

            // Settings
            $group->get ('/settings',        [SettingsController::class, 'index'])->setName('settings');
            $group->post('/settings',        [SettingsController::class, 'save']);
            $group->post('/settings/action', [SettingsController::class, 'action'])->setName('settings_action');
            // v0.7.5: ручные алиасы тикеров
            // v0.8.0: live API ключи
            $group->post('/settings/live-keys',         [SettingsController::class, 'saveLiveKeys'])->setName('settings_live_keys_save');
            $group->post('/settings/live-keys/delete',  [SettingsController::class, 'deleteLiveKeys'])->setName('settings_live_keys_delete');
            $group->post('/settings/live-keys/test',    [SettingsController::class, 'testLiveKeys'])->setName('settings_live_keys_test');

            // v0.9.0: Мульти-аккаунты Bybit (subaccounts).
            $group->get ('/accounts',                       [BybitAccountsController::class, 'index'])->setName('accounts');
            $group->post('/accounts/create',                [BybitAccountsController::class, 'create'])->setName('accounts_create');
            $group->post('/accounts/{id}/update-keys',      [BybitAccountsController::class, 'updateKeys'])->setName('accounts_update_keys');
            $group->post('/accounts/{id}/rename',           [BybitAccountsController::class, 'rename'])->setName('accounts_rename');
            $group->post('/accounts/{id}/toggle',           [BybitAccountsController::class, 'toggle'])->setName('accounts_toggle');
            // v0.9.0-step7 task5: per-strategy toggle (s1/s2/s3)
            $group->post('/accounts/{id}/toggle-strategy/{strat}', [BybitAccountsController::class, 'toggleStrategy'])->setName('accounts_toggle_strategy');
            // v0.9.1: режим риска (conservative|standard) — формула движения trailing §6.3
            $group->post('/accounts/{id}/set-risk-mode',    [BybitAccountsController::class, 'setRiskMode'])->setName('accounts_set_risk_mode');
            $group->post('/accounts/{id}/archive',          [BybitAccountsController::class, 'archive'])->setName('accounts_archive');
            $group->post('/accounts/{id}/test',             [BybitAccountsController::class, 'test'])->setName('accounts_test');

            $group->post('/settings/aliases/add',      [SettingsController::class, 'addAlias'])->setName('settings_aliases_add');
            $group->post('/settings/aliases/edit',     [SettingsController::class, 'editAlias'])->setName('settings_aliases_edit');
            $group->post('/settings/aliases/delete',   [SettingsController::class, 'deleteAlias'])->setName('settings_aliases_delete');
            $group->post('/settings/aliases/backfill', [SettingsController::class, 'backfillAliases'])->setName('settings_aliases_backfill');

        })->add(new AuthMiddleware());

        return $app;
    }
}
