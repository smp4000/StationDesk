<?php

namespace Tests\Unit;

use App\Billing\SubscriptionPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Monatsanker und exakte Zeitgrenzen ohne Datenbankzugriff. */
class SubscriptionPeriodTest extends TestCase
{
    /** Ein verkürzter Februar verändert den ursprünglichen Monatstag nicht dauerhaft. */
    public function test_short_month_does_not_shift_the_original_anchor(): void
    {
        $anchor = CarbonImmutable::parse('2027-01-31 12:00:00', 'UTC');
        $period = (new SubscriptionPeriod)->at($anchor, CarbonImmutable::parse('2027-03-01', 'UTC'));
        $this->assertSame('2027-02-28 12:00:00', $period['start']->toDateTimeString());
        $this->assertSame('2027-03-31 12:00:00', $period['end']->toDateTimeString());
    }

    public function test_exact_boundary_belongs_to_the_new_period_and_leap_year_is_supported(): void
    {
        $anchor = CarbonImmutable::parse('2028-01-31 12:00:00', 'UTC');
        $period = (new SubscriptionPeriod)->at($anchor, CarbonImmutable::parse('2028-02-29 12:00:00', 'UTC'));
        $this->assertSame('2028-02-29 12:00:00', $period['start']->toDateTimeString());
        $this->assertSame('2028-03-31 12:00:00', $period['end']->toDateTimeString());
    }

    public function test_before_anchor_is_not_a_monthly_period(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SubscriptionPeriod)->at(CarbonImmutable::parse('2026-10-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
    }
}
