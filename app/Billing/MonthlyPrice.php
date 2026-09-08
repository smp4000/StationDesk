<?php

namespace App\Billing;

use InvalidArgumentException;

/** Unveränderlicher Bruttopreis in Cent; speichert angenommene Konditionen unabhängig vom späteren Katalog. */
final readonly class MonthlyPrice
{
    /** Prüft Beträge und Steuersatz; die vorläufige Vorgabe lautet 100 Cent bei 1900 Basispunkten. */
    public function __construct(public int $grossCents, public int $taxBasisPoints = 1900)
    {
        if ($grossCents < 1 || $grossCents > 100_000_000 || $taxBasisPoints < 0 || $taxBasisPoints > 10000) {
            throw new InvalidArgumentException('Ungültiger Preis oder Umsatzsteuersatz.');
        }
    }

    /** Berechnet Netto kaufmännisch in Ganzzahlarithmetik, ohne Fließkommafehler. */
    public function netCents(): int
    {
        $denominator = 10000 + $this->taxBasisPoints;

        return intdiv($this->grossCents * 10000 + intdiv($denominator, 2), $denominator);
    }

    /** Steuer ist der Rest zum bestätigten Bruttobetrag; Netto plus Steuer bleibt exakt Brutto. */
    public function taxCents(): int
    {
        return $this->grossCents - $this->netCents();
    }
}
