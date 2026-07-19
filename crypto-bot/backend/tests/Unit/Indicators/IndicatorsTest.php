<?php

namespace Tests\Unit\Indicators;

use App\Indicators\Ema;
use App\Indicators\Rsi;
use App\Indicators\Sma;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

class IndicatorsTest extends TestCase
{
    /** @return BigDecimal[] */
    private function toBig(array $values): array
    {
        return array_map(fn ($v) => BigDecimal::of((string) $v), $values);
    }

    public function test_sma_returns_null_when_insufficient_data(): void
    {
        $this->assertNull(Sma::calc($this->toBig([1, 2]), 3));
    }

    public function test_sma_computes_average_of_window(): void
    {
        $sma = Sma::calc($this->toBig([2, 4, 6, 8]), 2);
        $this->assertSame('7', (string) $sma->toScale(0));
    }

    public function test_ema_returns_null_before_seed(): void
    {
        $this->assertNull(Ema::last($this->toBig([1, 2]), 5));
    }

    public function test_ema_last_is_close_to_recent_values_on_rising_series(): void
    {
        $ema = Ema::last($this->toBig([10, 11, 12, 13, 14, 15]), 3);
        $this->assertNotNull($ema);
        // EMA of a steadily rising series sits between the seed and the last value.
        $this->assertTrue($ema->isGreaterThan(BigDecimal::of('11')));
        $this->assertTrue($ema->isLessThan(BigDecimal::of('15')));
    }

    public function test_rsi_null_when_insufficient_data(): void
    {
        $this->assertNull(Rsi::calc($this->toBig([1, 2, 3]), 14));
    }

    public function test_rsi_is_100_for_monotonic_increase(): void
    {
        $rsi = Rsi::calc($this->toBig(range(1, 20)), 14);
        $this->assertSame('100', (string) $rsi->toScale(0));
    }

    public function test_rsi_is_bounded(): void
    {
        $rsi = Rsi::calc($this->toBig([10, 12, 11, 13, 12, 14, 13, 15, 14, 16, 15, 17, 16, 18, 17, 19]), 14);
        $this->assertNotNull($rsi);
        $f = (float) (string) $rsi;
        $this->assertGreaterThanOrEqual(0, $f);
        $this->assertLessThanOrEqual(100, $f);
    }
}
