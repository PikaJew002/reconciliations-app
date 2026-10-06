<?php

namespace Tests\Unit\Reconciliation;

use App\Services\Reconciliation\SalesTaxCalculator;
use Tests\TestCase;

class SalesTaxCalculatorTest extends TestCase
{
    public function test_six_percent_of_the_walmart_order_rounds_once(): void
    {
        $this->assertSame(6000, SalesTaxCalculator::scaleRate('0.06000'));
        $this->assertSame(6000, SalesTaxCalculator::scaleRate(0.06));
        $this->assertSame('0.06000', SalesTaxCalculator::normalizeRate('0.06'));

        $this->assertSame(4662000, SalesTaxCalculator::lineNanos(777, '0.06000'));
        $this->assertSame('0.46620', SalesTaxCalculator::formatFiveDecimals(4662000));

        $this->assertSame(1543, SalesTaxCalculator::roundedCents(25724, '0.06000'));
        $this->assertSame(539, SalesTaxCalculator::roundedCents(8989, '0.06000'));
        $this->assertSame('15.43440', SalesTaxCalculator::formatFiveDecimals(
            SalesTaxCalculator::lineNanos(25724, '0.06000'),
        ));
        $this->assertSame('5.39340', SalesTaxCalculator::formatFiveDecimals(
            SalesTaxCalculator::lineNanos(8989, '0.06000'),
        ));
    }
}
