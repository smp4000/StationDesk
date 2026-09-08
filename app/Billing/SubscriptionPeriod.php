<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Monatsgrenzen ab dem unveränderlichen UTC-Abrechnungsanker, ohne Drift nach kurzen Monaten. */
class SubscriptionPeriod
{
    /** Beginn ist inklusive, Ende exklusiv; vor dem Abrechnungsanker muss der Aufrufer die Trial-Phase behandeln. */
    public function at(CarbonImmutable $anchor, CarbonImmutable $time): array
    {
        $anchor = $anchor->utc();
        $time = $time->utc();
        if ($time->lessThan($anchor)) {
            throw new InvalidArgumentException('Monatsperiode beginnt erst nach dem Trial.');
        }
        $index = ($time->year - $anchor->year) * 12 + $time->month - $anchor->month;
        if ($anchor->addMonthsNoOverflow($index)->greaterThan($time)) {
            $index--;
        }

        // Jede Grenze wird erneut vom ursprünglichen Anker aus berechnet: 31.01. → 28.02. → 31.03.
        return ['start' => $anchor->addMonthsNoOverflow($index), 'end' => $anchor->addMonthsNoOverflow($index + 1)];
    }
}
