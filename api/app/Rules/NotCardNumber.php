<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Payment references are slip/wallet reference numbers. Anything shaped like a card number (PAN) is refused so it is never stored. */
class NotCardNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/[\s-]/', '', (string) $value);
        if (preg_match('/^\d{13,19}$/', $digits) && self::luhn($digits)) {
            $fail('Do not enter a card number. Use the terminal slip or wallet reference instead.');
        }
    }

    private static function luhn(string $n): bool
    {
        $sum = 0;
        foreach (array_reverse(str_split($n)) as $i => $d) {
            $d = (int) $d * ($i % 2 ? 2 : 1);
            $sum += $d > 9 ? $d - 9 : $d;
        }

        return $sum % 10 === 0;
    }
}
