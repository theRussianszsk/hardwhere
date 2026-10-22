<?php

namespace App\Ai;

use App\Models\AttributeDefinition;
use App\Models\Category;
use Illuminate\Support\Facades\Cache;

/**
 * The catalog's own words for categories and attributes, sent to the vision model so its answer can be compared field by field
 * with the database (e.g. "amperage_a": 16 instead of a free-form "current_rating_a", "Electrical Supplies" instead of "Electrical Components").
 */
class CatalogVocabulary
{
    public const CACHE_KEY = 'ai.catalog_vocabulary';

    /** @return array{categories: list<string>, attributes: list<string>} */
    public function forPrompt(): array
    {
        return Cache::remember(self::CACHE_KEY, 600, fn () => [
            'categories' => Category::query()->with('parent')->orderBy('sort')->get()
                ->map(fn (Category $c) => $c->parent ? "{$c->parent->name} > {$c->name}" : $c->name)->values()->all(),
            'attributes' => AttributeDefinition::query()->orderBy('key')->get()->unique('key')
                ->map(fn (AttributeDefinition $d) => $d->key.($d->unit ? " ({$d->unit})" : '').($d->data_type === 'number' ? ' number' : ''))->values()->all(),
        ]);
    }

    /** @param  array{categories?: list<string>, attributes?: list<string>}|null  $vocabulary */
    public static function promptSection(?array $vocabulary): string
    {
        if (! $vocabulary || (empty($vocabulary['categories']) && empty($vocabulary['attributes']))) {
            return '';
        }

        return "\nUse the store catalog's vocabulary so the answer can be matched to its database:\n"
            .'- "category": copy the closest one of these exactly (prefer the most specific): '.implode('; ', $vocabulary['categories'] ?? [])."\n"
            .'- "attributes": use only these keys, in these units, when the value is visible: '.implode('; ', $vocabulary['attributes'] ?? [])."\n"
            ."  Convert units if needed (e.g. 16A -> amperage_a: 16, 3/4 inch -> diameter_mm: 19.05). Put anything else in notes, not in attributes.\n";
    }
}
