<?php

namespace Tests\Unit;

use App\Support\Quantity;
use InvalidArgumentException;
use Tests\TestCase;

class QuantityTest extends TestCase
{
    public function test_it_converts_decimal_strings_to_millis(): void
    {
        $this->assertSame(1125, Quantity::toMillis('1.125'));
        $this->assertSame(10000, Quantity::toMillis('10'));
        $this->assertSame(10000, Quantity::toMillis('10.000'));
        $this->assertSame(125, Quantity::toMillis('0.125'));
    }

    public function test_it_converts_numeric_input_to_millis(): void
    {
        $this->assertSame(1125, Quantity::toMillis(1.125));
        $this->assertSame(2000, Quantity::toMillis(2));
    }

    public function test_it_formats_millis_back_to_three_decimals(): void
    {
        $this->assertSame('1.125', Quantity::fromMillis(1125));
        $this->assertSame('10.000', Quantity::fromMillis(10000));
        $this->assertSame('0.125', Quantity::fromMillis(125));
        $this->assertSame('0.000', Quantity::fromMillis(0));
    }

    public function test_round_trip_is_exact(): void
    {
        foreach (['0.001', '1.125', '999.999', '123456.789'] as $value) {
            $this->assertSame($value, Quantity::fromMillis(Quantity::toMillis($value)));
        }
    }

    public function test_addition_has_no_float_drift(): void
    {
        $sum = Quantity::toMillis('0.1') + Quantity::toMillis('0.2');

        $this->assertSame('0.300', Quantity::fromMillis($sum));
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Quantity::toMillis('1.1234');
    }
}
