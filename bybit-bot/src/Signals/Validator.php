<?php
declare(strict_types=1);

namespace BybitBot\Signals;

/**
 * Валидация одного сигнала из signalsHourly.json.
 *
 * Эталонный формат (из реального файла finManager):
 * {
 *   "date":      "2026-05-09",
 *   "time":      "15:00:07",
 *   "savedAt":   "2026-05-09 15:00:07",
 *   "symbol":    "TON",
 *   "side":      "long" | "short",
 *   "target":    20.05,
 *   "strategy":  1 | 2 | 3,
 *   "potential": true | false,
 *   "rsi":       86.27,
 *   "weights": {"w7":2,"w14":0,"w30":4,"wAll":5}
 * }
 *
 * Обязательные: date, time, savedAt, symbol, side, target, strategy.
 * Опциональные: potential, rsi, weights.
 */
final class Validator
{
    private const ALLOWED_SIDES      = ['long', 'short'];
    private const ALLOWED_STRATEGIES = [1, 2, 3];

    /**
     * @return array{ok:bool, errors:array<string>, signal:array}
     */
    public static function validate(array $sig, int $index = -1): array
    {
        $errors = [];

        // date
        $date = (string)($sig['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = "date: ожидается YYYY-MM-DD, получено '{$date}'";
        }

        // time
        $time = (string)($sig['time'] ?? '');
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            $errors[] = "time: ожидается HH:MM:SS, получено '{$time}'";
        }

        // savedAt
        $savedAt = (string)($sig['savedAt'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $savedAt)) {
            $errors[] = "savedAt: ожидается 'YYYY-MM-DD HH:MM:SS', получено '{$savedAt}'";
        }

        // symbol
        $symbol = isset($sig['symbol']) ? strtoupper(trim((string)$sig['symbol'])) : '';
        if ($symbol === '' || !preg_match('/^[A-Z0-9]+$/', $symbol)) {
            $errors[] = "symbol: пустой или содержит недопустимые символы ('{$symbol}')";
        }

        // side
        $side = strtolower((string)($sig['side'] ?? ''));
        if (!in_array($side, self::ALLOWED_SIDES, true)) {
            $errors[] = "side: ожидается long|short, получено '{$side}'";
        }

        // target — допускаем int/float/строку с числом
        if (!isset($sig['target']) || !is_numeric($sig['target'])) {
            $errors[] = 'target: должно быть числом';
            $target = 0.0;
        } else {
            $target = (float)$sig['target'];
            if ($target <= 0) {
                $errors[] = "target: должно быть > 0, получено {$target}";
            }
        }

        // strategy (поле источника signalsHourly.json — тип сигнала: 1|2|3)
        // В БД хранится как signal_type, но во внешнем JSON называется strategy — оставляем как есть.
        $strategy = isset($sig['strategy']) && is_numeric($sig['strategy']) ? (int)$sig['strategy'] : 0;
        if (!in_array($strategy, self::ALLOWED_STRATEGIES, true)) {
            $errors[] = "strategy: ожидается 1|2|3, получено '{$strategy}'";
        }

        // potential — bool / 0|1
        $potential = false;
        if (array_key_exists('potential', $sig)) {
            if (is_bool($sig['potential'])) {
                $potential = $sig['potential'];
            } elseif (is_numeric($sig['potential'])) {
                $potential = (bool)(int)$sig['potential'];
            } else {
                $errors[] = "potential: ожидается bool, получено '" . gettype($sig['potential']) . "'";
            }
        }

        // rsi — опциональное число
        $rsi = null;
        if (array_key_exists('rsi', $sig) && $sig['rsi'] !== null) {
            if (is_numeric($sig['rsi'])) {
                $rsi = (float)$sig['rsi'];
            } else {
                $errors[] = 'rsi: должно быть числом или null';
            }
        }

        // weights — опционально
        $weights = ['w7' => null, 'w14' => null, 'w30' => null, 'wAll' => null];
        if (isset($sig['weights']) && is_array($sig['weights'])) {
            foreach (['w7', 'w14', 'w30', 'wAll'] as $k) {
                if (array_key_exists($k, $sig['weights']) && $sig['weights'][$k] !== null) {
                    if (is_numeric($sig['weights'][$k])) {
                        $weights[$k] = (int)$sig['weights'][$k];
                    } else {
                        $errors[] = "weights.{$k}: должно быть целым";
                    }
                }
            }
        }

        if (count($errors) > 0) {
            return ['ok' => false, 'errors' => $errors, 'signal' => $sig];
        }

        return [
            'ok'     => true,
            'errors' => [],
            'signal' => [
                'date'      => $date,
                'time'      => $time,
                'savedAt'   => $savedAt,
                'symbol'    => $symbol,
                'side'      => $side,
                'target'    => $target,
                'strategy'  => $strategy,  // тип сигнала из источника (signal_type в БД)
                'potential' => $potential,
                'rsi'       => $rsi,
                'weights'   => $weights,
            ],
        ];
    }
}
