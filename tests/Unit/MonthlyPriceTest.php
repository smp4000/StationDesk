<?php

namespace Tests\Unit;

use App\Billing\MonthlyPrice;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Prüft Bruttobindung und Cent-Rundung unabhängig von Zahlungsdienst oder Datenbank. */
class MonthlyPriceTest extends TestCase
{
    public function test_confirmed_one_euro_price_contains_exactly_sixteen_cents_tax(): void
    {
        $price = new MonthlyPrice(100);
        $this->assertSame(84, $price->netCents());
        $this->assertSame(16, $price->taxCents());
    }

    public function test_rounding_never_changes_the_confirmed_gross_amount(): void
    {
        foreach ([1, 2, 3, 99, 100, 119, 999, 10000, 100_000_000] as $amount) {
            $price = new MonthlyPrice($amount);
            $this->assertSame($amount, $price->netCents() + $price->taxCents());
        }
    }

    public function test_invalid_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MonthlyPrice(-1);
    }
}
