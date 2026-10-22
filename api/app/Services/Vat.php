<?php

namespace App\Services;

final class Vat
{
    /** VAT component of a VAT-inclusive amount in centavos, rounded half up. */
    public static function includedIn(int $grossCents, int $rateBps): int
    {
        return intdiv($grossCents * $rateBps + intdiv(10000 + $rateBps, 2), 10000 + $rateBps);
    }
}
