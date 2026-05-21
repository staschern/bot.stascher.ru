<?php
declare(strict_types=1);

namespace BybitBot\Core;

use InvalidArgumentException;

/**
 * Округление к шагу инструмента (tickSize / qtyStep).
 *
 * См. spec.md §10 — единый хелпер. Нарушение направления = ошибка реализации.
 */
final class Rounding
{
    public const UP      = 'up';
    public const DOWN    = 'down';
    public const NEAREST = 'nearest';

    /**
     * Округляет $value к ближайшему кратному $step в указанную сторону.
     *
     * Используется bcmath/целочисленная арифметика, чтобы избежать накопления
     * ошибок плавающей точки на ценах вида 0.0068150 при tickSize 0.0000001.
     *
     * @param float  $value     значение
     * @param float  $step      шаг (tickSize или qtyStep)
     * @param string $direction up|down|nearest
     */
    public static function roundToStep(float $value, float $step, string $direction): float
    {
        if ($step <= 0) {
            throw new InvalidArgumentException("step должен быть > 0 (получено: {$step})");
        }
        if (!in_array($direction, [self::UP, self::DOWN, self::NEAREST], true)) {
            throw new InvalidArgumentException("Неизвестное направление округления: {$direction}");
        }

        // Кол-во знаков после точки в шаге → масштабирующий множитель.
        // 0.0001 → scale=10000, 0.5 → scale=2 (через десятичную точку).
        $stepStr   = rtrim(rtrim(sprintf('%.18F', $step), '0'), '.');
        $decPos    = strpos($stepStr, '.');
        $decimals  = $decPos === false ? 0 : strlen($stepStr) - $decPos - 1;
        $scale     = (int)round(pow(10, $decimals));

        $valueScaled = $value * $scale;
        $stepScaled  = (int)round($step * $scale);
        if ($stepScaled <= 0) {
            // Шаг слишком мал для нашего scale — fallback на чистый float.
            return self::roundFallback($value, $step, $direction);
        }

        switch ($direction) {
            case self::UP:
                $quotient = (int)ceil($valueScaled / $stepScaled - 1e-9);
                break;
            case self::DOWN:
                $quotient = (int)floor($valueScaled / $stepScaled + 1e-9);
                break;
            case self::NEAREST:
            default:
                $quotient = (int)round($valueScaled / $stepScaled);
                break;
        }

        return ($quotient * $stepScaled) / $scale;
    }

    private static function roundFallback(float $value, float $step, string $direction): float
    {
        $q = $value / $step;
        switch ($direction) {
            case self::UP:
                $rounded = ceil($q  - 1e-12);
                break;
            case self::DOWN:
                $rounded = floor($q + 1e-12);
                break;
            case self::NEAREST:
            default:
                $rounded = round($q);
                break;
        }
        return $rounded * $step;
    }

    /** Округление к десятым (для trailing_pct: floor_to_tenth). См. §6.3. */
    public static function floorToTenth(float $value): float
    {
        return floor($value * 10) / 10;
    }
}
