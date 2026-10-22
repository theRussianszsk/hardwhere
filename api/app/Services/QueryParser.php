<?php

namespace App\Services;

use App\Support\Text;

/** Tiny natural-language helper: pulls prices and common electrical/size specs out of free text, leaves the rest as search words. */
final class QueryParser
{
    /** @return array{text: string, max_price_cents: ?int, min_price_cents: ?int, attributes: array<string, float>} */
    public static function parse(string $q): array
    {
        $attrs = [];
        $max = $min = null;

        $q = preg_replace_callback('/(?:under|below|less than|max(?:imum)?|up to|hanggang)\s*(?:₱|php|p)?\s*([\d,]+(?:\.\d+)?)(?:\s*(?:pesos?|php)\b)?/iu', function ($m) use (&$max) {
            $max = (int) round((float) str_replace(',', '', $m[1]) * 100);

            return ' ';
        }, $q);
        $q = preg_replace_callback('/(?:over|above|more than|min(?:imum)?)\s*(?:₱|php|p)?\s*([\d,]+(?:\.\d+)?)(?:\s*(?:pesos?|php)\b)?/iu', function ($m) use (&$min) {
            $min = (int) round((float) str_replace(',', '', $m[1]) * 100);

            return ' ';
        }, $q);

        foreach (['voltage_v' => 'v(?:olts?)?', 'wattage_w' => 'w(?:atts?)?', 'amperage_a' => 'a(?:mps?)?'] as $key => $unit) {
            $q = preg_replace_callback('/\b(\d+(?:\.\d+)?)\s*'.$unit.'\b/iu', function ($m) use (&$attrs, $key) {
                $attrs[$key] = (float) $m[1];

                return ' ';
            }, $q);
        }

        return ['text' => Text::normalize($q), 'max_price_cents' => $max, 'min_price_cents' => $min, 'attributes' => $attrs];
    }
}
