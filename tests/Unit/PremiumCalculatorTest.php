<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    protected PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    public function test_calculates_premium_for_plus_international_plan_correctly(): void
    {
        // PLUS (20) * 5 days * age 28 (1.00) * INTERNATIONAL (1.80) * 300,000 (1.25)
        // 20 * 5 * 1.00 * 1.80 * 1.25 = 225.00
        $premium = $this->calculator->calculate(
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14',
            planCode: 'PLUS',
            destination: 'INTERNATIONAL',
            sumInsured: 300000
        );

        $this->assertEquals(225.00, $premium);
    }

    public function test_calculates_premium_for_basic_domestic_senior(): void
    {
        // BASIC (12) * 3 days * age 65 (1.50) * DOMESTIC (1.00) * 100,000 (1.00)
        // 12 * 3 * 1.50 * 1.00 * 1.00 = 54.00
        $premium = $this->calculator->calculate(
            dateOfBirth: '1961-01-01',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-03',
            planCode: 'BASIC',
            destination: 'DOMESTIC',
            sumInsured: 100000
        );

        $this->assertEquals(54.00, $premium);
    }
}
