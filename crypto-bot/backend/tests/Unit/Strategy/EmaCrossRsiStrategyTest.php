<?php

namespace Tests\Unit\Strategy;

use App\Exchange\Dto\Candle;
use App\Models\Position;
use App\Strategy\EmaCrossRsiStrategy;
use App\Strategy\Signal;
use Brick\Math\BigDecimal;
use Tests\TestCase;

class EmaCrossRsiStrategyTest extends TestCase
{
    private array $params = [
        'fast' => 2,
        'slow' => 3,
        'rsi_period' => 14,
        'rsi_overbought' => 200, // effectively disable the RSI guard for the test
        'stop_loss_pct' => 0.03,
        'take_profit_pct' => 0.06,
        'position_size_pct' => 0.20,
    ];

    /** @param float[] $closes */
    private function candles(array $closes): array
    {
        return array_map(function ($c, $i) {
            $b = BigDecimal::of((string) $c);

            return new Candle($i * 3_600_000, $b, $b, $b, $b, BigDecimal::of('1'));
        }, $closes, array_keys($closes));
    }

    public function test_bullish_crossover_produces_buy_when_flat(): void
    {
        $closes = array_merge(array_fill(0, 30, 100.0), [110.0]);
        $signal = (new EmaCrossRsiStrategy)->decide($this->candles($closes), null, $this->params);

        $this->assertSame(Signal::BUY, $signal->side);
        $this->assertGreaterThan(0.0, $signal->sizeFraction);
    }

    public function test_flat_series_holds(): void
    {
        $closes = array_fill(0, 31, 100.0);
        $signal = (new EmaCrossRsiStrategy)->decide($this->candles($closes), null, $this->params);

        $this->assertSame(Signal::HOLD, $signal->side);
    }

    public function test_stop_loss_forces_sell_on_open_position(): void
    {
        $position = new Position(['quantity' => '1', 'avg_entry_price' => '100', 'status' => 'open']);
        // Price well below the 3% stop (97).
        $closes = array_merge(array_fill(0, 30, 100.0), [90.0]);

        $signal = (new EmaCrossRsiStrategy)->decide($this->candles($closes), $position, $this->params);

        $this->assertSame(Signal::SELL, $signal->side);
        $this->assertStringContainsString('stop-loss', $signal->reason);
    }

    public function test_insufficient_history_holds(): void
    {
        $signal = (new EmaCrossRsiStrategy)->decide($this->candles([1.0, 2.0]), null, $this->params);
        $this->assertSame(Signal::HOLD, $signal->side);
    }
}
