<?php
declare(strict_types=1);

namespace BybitBot\Tests;

use BybitBot\Core\Rounding;
use PHPUnit\Framework\TestCase;

final class RoundingTest extends TestCase
{
    public function testRoundUp(): void
    {
        self::assertEqualsWithDelta(0.1235, Rounding::roundToStep(0.12345, 0.0001, 'up'),  1e-9);
        self::assertEqualsWithDelta(0.1234, Rounding::roundToStep(0.12345, 0.0001, 'down'), 1e-9);
    }

    public function testTickSizeSmall(): void
    {
        // tickSize типа 0.0000001 (на низкоценовых альтах)
        $tick = 0.0000001;
        $val  = 0.0068153;
        self::assertEqualsWithDelta(0.0068154, Rounding::roundToStep($val, $tick, 'up'),   1e-12);
        self::assertEqualsWithDelta(0.0068153, Rounding::roundToStep($val, $tick, 'down'), 1e-12);
    }

    public function testQtyStep(): void
    {
        self::assertEqualsWithDelta(2.0, Rounding::roundToStep(1.7, 1.0, 'up'),   1e-9);
        self::assertEqualsWithDelta(1.0, Rounding::roundToStep(1.7, 1.0, 'down'), 1e-9);
        self::assertEqualsWithDelta(2.0, Rounding::roundToStep(1.5, 1.0, 'nearest'), 1e-9);
    }

    public function testFloorToTenth(): void
    {
        self::assertEqualsWithDelta(2.2, Rounding::floorToTenth(2.27), 1e-9);
        self::assertEqualsWithDelta(0.0, Rounding::floorToTenth(0.09), 1e-9);
        self::assertEqualsWithDelta(1.0, Rounding::floorToTenth(1.0),  1e-9);
    }

    public function testInvalidStep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Rounding::roundToStep(1.0, 0, 'up');
    }
}
