<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Text
{
    /** Lowercase ASCII words separated by single spaces. */
    public static function normalize(?string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($s ?? ''))));
    }

    /** Alphanumerics only, for comparing model numbers: "HP 1630" == "hp-1630". */
    public static function key(?string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::ascii(mb_strtolower($s ?? '')));
    }

    /** @param  array<string, mixed>  $axes */
    public static function variantKey(array $axes): string
    {
        $axes = array_filter($axes, fn ($v) => $v !== null && $v !== '' && $v !== 1);
        ksort($axes);

        return $axes ? implode('|', array_map(fn ($k, $v) => $k.'='.self::key((string) $v), array_keys($axes), $axes)) : 'default';
    }

    /** Philippine mobile number to +63 form, or null when it is not one. */
    public static function phone(?string $p): ?string
    {
        $d = preg_replace('/\D+/', '', $p ?? '');
        if (preg_match('/^(?:63|0)?(9\d{9})$/', $d, $m)) {
            return '+63'.$m[1];
        }

        return null;
    }
}
