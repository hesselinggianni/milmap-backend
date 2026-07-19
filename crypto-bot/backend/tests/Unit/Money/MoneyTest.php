<?php

namespace Tests\Unit\Money;

use App\Support\Money;
use App\Support\Quantity;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_addition_is_exact_no_float_drift(): void
    {
        $sum = Money::of('0.1')->plus(Money::of('0.2'));
        $this->assertSame('0.3000000000', $sum->toScale());
    }

    public function test_cannot_mix_assets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of('1', 'EUR')->plus(Money::of('1', 'USD'));
    }

    public function test_quantity_value_at_price(): void
    {
        $value = Quantity::of('2', 'BTC')->valueAt('50000', 'EUR');
        $this->assertSame('100000.00', $value->toScale(2));
        $this->assertSame('EUR', $value->asset);
    }

    public function test_scale_formatting(): void
    {
        $this->assertSame('12.50', Money::of('12.5')->toScale(2));
    }
}
