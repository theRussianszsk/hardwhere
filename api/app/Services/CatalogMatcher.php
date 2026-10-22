<?php

namespace App\Services;

use App\Ai\ProductExtraction;
use App\Models\AttributeDefinition;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Combines barcode, model/SKU/part-number, brand, text similarity (pg_trgm), category and structured attribute comparison.
 * It only ever SUGGESTS: callers must show the result to a human and never merge or overwrite automatically.
 */
class CatalogMatcher
{
    public const EXACT = 'exact_match';

    public const SAME = 'likely_same_product';

    public const VARIANT = 'likely_variant';

    public const DUPLICATE = 'possible_duplicate';

    public const NEW = 'new_product_candidate';

    public const INSUFFICIENT = 'insufficient_confidence';

    /** @var array<string, string>|null attribute key => human label, for keys that make products commercially different */
    private ?array $axes = null;

    /**
     * @param  Builder<ProductVariant>|null  $restrictTo  limits retrieval, e.g. to variants a tenant carries
     * @return array{classification: string, requires_confirmation: bool, warnings: list<string>, candidates: list<array<string, mixed>>}
     */
    public function match(ProductExtraction $x, ?int $tenantId = null, ?Builder $restrictTo = null, int $limit = 6): array
    {
        $warnings = [];
        $barcode = $x->barcodeText && strlen($x->barcodeText) >= 8 ? $x->barcodeText : null;
        $modelKey = Text::key($x->model ?? $x->partNumber);

        $variants = $this->retrieve($x, $tenantId, $barcode, $modelKey, $restrictTo);
        $sims = $variants->isEmpty() ? [] : DB::table('products')->whereIn('id', $variants->pluck('product_id')->unique())
            ->selectRaw('id, similarity(search_text, ?) AS sim', [$this->queryText($x)])->pluck('sim', 'id')->all();
        $barcodeVariant = $barcode ? ProductBarcode::query()->where('code', $barcode)->value('variant_id') : null;

        $candidates = [];
        foreach ($variants as $variant) {
            $c = $this->score($x, $variant, (float) ($sims[$variant->product_id] ?? 0), $modelKey, $barcodeVariant === $variant->id);
            $c && $candidates[] = $c;
        }
        // Contradictory evidence (the printed barcode belongs to another brand/model) means nothing may be called an exact match.
        $barcodeConflict = collect($candidates)->contains(fn ($c) => collect($c['warnings'])->contains(fn ($w) => str_starts_with($w, 'Conflicting barcode')));
        if ($barcodeConflict) {
            $candidates = array_map(fn ($c) => $c['classification'] === self::EXACT ? [...$c, 'classification' => self::SAME] : $c, $candidates);
        }
        // Exact matches first, then by score; a sibling that differs on a commercial axis ranks below an equally-scored real match.
        $order = fn ($c) => [$c['classification'] === self::EXACT ? 1 : 0, $c['score'] - ($c['classification'] === self::VARIANT ? 0.15 : 0)];
        usort($candidates, fn ($a, $b) => $order($b) <=> $order($a));
        $candidates = array_slice($candidates, 0, $limit);
        $conflictWarnings = collect($candidates)->pluck('warnings')->flatten()->filter(fn ($w) => str_starts_with($w, 'Conflicting barcode'))->all();

        if ($x->conf('brand') > 0 && $x->conf('brand') < 0.6 && $x->brand) {
            $warnings[] = 'Low-confidence brand';
        }
        if ($x->conf('model') > 0 && $x->conf('model') < 0.6 && $x->model) {
            $warnings[] = 'Low-confidence model';
        }
        if (count(array_filter($candidates, fn ($c) => in_array($c['classification'], [self::EXACT, self::SAME], true))) > 1) {
            $warnings[] = 'Possible duplicate: several catalog entries look the same';
        }

        $top = $candidates[0] ?? null;
        $weak = ! $x->imageSufficient || $x->overallConfidence < 0.35;
        if (! $x->imageSufficient) {
            $warnings[] = 'Image insufficient for reliable identification';
        }

        $classification = match (true) {
            $weak && ! ($top && $top['classification'] === self::EXACT) => self::INSUFFICIENT,
            $top !== null => $top['classification'],
            default => self::NEW,
        };
        $barcodeExact = ! $barcodeConflict && $top && $top['classification'] === self::EXACT && $barcodeVariant === $top['variant_id'];
        $warnings = array_unique(array_merge($warnings, $top['warnings'] ?? [], $conflictWarnings));
        $warnings = array_values($warnings);

        return [
            'classification' => $classification,
            'requires_confirmation' => ! ($barcodeExact && ! $warnings),
            'warnings' => $warnings,
            'candidates' => $candidates,
        ];
    }

    /** @return Collection<int, ProductVariant> */
    private function retrieve(ProductExtraction $x, ?int $tenantId, ?string $barcode, string $modelKey, ?Builder $restrictTo): Collection
    {
        $ids = collect();
        $barcode && $ids = $ids->merge(ProductBarcode::query()->where('code', $barcode)->pluck('variant_id'));

        if (strlen($modelKey) >= 3) {
            $col = fn (string $c) => "regexp_replace(lower($c), '[^a-z0-9]', '', 'g')";
            $ids = $ids->merge(ProductVariant::query()->where(function ($q) use ($modelKey, $col) {
                foreach (['product_variants.model_number', 'product_variants.part_number'] as $c) {
                    $q->orWhereRaw($col($c).' = ?', [$modelKey])
                        ->orWhereRaw($col($c).' <> \'\' AND (? LIKE '.$col($c)." || '%' OR ".$col($c).' LIKE ? || \'%\')', [$modelKey, $modelKey]);
                }
            })->pluck('id'));
            $ids = $ids->merge(DB::table('product_aliases')->whereRaw("replace(normalized, ' ', '') = ?", [$modelKey])
                ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($w) => $w->orWhere('tenant_id', $tenantId)))->whereNotNull('variant_id')->pluck('variant_id'));
        }

        $text = $this->queryText($x);
        if ($text !== '') {
            $productIds = DB::table('products')->whereRaw('similarity(search_text, ?) > 0.18', [$text])->orderByRaw('similarity(search_text, ?) DESC', [$text])->limit(12)->pluck('id');
            $ids = $ids->merge(ProductVariant::query()->whereIn('product_id', $productIds)->pluck('id'));
        }

        return ProductVariant::query()->with(['product.category'])->whereIn('product_variants.id', $ids->unique()->values())
            ->whereHas('product', fn ($q) => $q->where(fn ($w) => $w->where('status', 'approved')->when($tenantId, fn ($w) => $w->orWhere('submitted_by_tenant_id', $tenantId))))
            ->when($restrictTo, fn ($q) => $q->whereIn('product_variants.id', $restrictTo->clone()->select('product_variants.id')))
            ->get();
    }

    private function queryText(ProductExtraction $x): string
    {
        return Text::normalize(implode(' ', array_filter([$x->brand, $x->name, $x->model, $x->partNumber])));
    }

    /** @return array<string, mixed>|null */
    private function score(ProductExtraction $x, ProductVariant $v, float $sim, string $modelKey, bool $barcodeHit): ?array
    {
        $p = $v->product;
        $matched = [];
        $mismatched = [];
        $warn = [];

        // brand: 1 match, 0 mismatch, null unknown
        $brand = $x->brand && $p->brand ? (Text::key($x->brand) === Text::key($p->brand) ? 1.0 : 0.0) : null;
        $brand === 1.0 && $matched[] = 'brand';
        $brand === 0.0 && $mismatched[] = 'brand';

        // model: 1 exact, 0.6 prefix (HP1630 vs HP1630K), 0 differs, null unknown
        $model = null;
        if (strlen($modelKey) >= 3) {
            // A variant's own model/part number wins; the family-level model only applies to variants that have none.
            $keys = array_filter([Text::key($v->model_number), Text::key($v->part_number)]) ?: array_filter([Text::key($p->model_number)]);
            $model = $keys ? 0.0 : null; // the catalog has no model number to compare against: unknown, not a mismatch
            foreach ($keys as $k) {
                if ($k === $modelKey) {
                    $model = 1.0;
                    break;
                }
                if (str_starts_with($k, $modelKey) || str_starts_with($modelKey, $k)) {
                    $model = max($model, 0.6);
                }
            }
        }
        $model === 1.0 && $matched[] = 'model';
        $model === 0.6 && $warn[] = 'Possible product variant: model number differs slightly ('.($v->model_number ?? $p->model_number).')';
        $model === 0.0 && $mismatched[] = 'model';

        $category = null;
        if ($x->category) {
            // Live models copy a catalog path such as "Electrical Supplies > Breakers & Panel Boards"; any level that names this product's category counts.
            $hints = array_map(Text::key(...), explode('>', $x->category));
            $cat = $p->category;
            $names = array_filter([Text::key($cat->name), Text::key($cat->slug), $cat->parent ? Text::key($cat->parent->name) : null]);
            $category = array_intersect($hints, $names) ? 1.0 : 0.25;
            $category === 1.0 ? $matched[] = 'category' : $mismatched[] = 'category';
        }

        // structured attributes; a mismatch on a variant axis means "different product to sell"
        $axes = $this->axes();
        $effective = array_merge($p->attributes ?? [], $v->attributes ?? [], ['pack_quantity' => $v->pack_quantity]);
        $given = $x->attributes + ($x->packQuantity ? ['pack_quantity' => $x->packQuantity] : []);
        $ok = 0;
        $bad = 0;
        $axisConflicts = [];
        foreach ($given as $key => $value) {
            if (! array_key_exists($key, $effective)) {
                continue;
            }
            $same = is_numeric($value) && is_numeric($effective[$key]) ? abs((float) $value - (float) $effective[$key]) < 0.0001 : Text::key((string) $value) === Text::key((string) $effective[$key]);
            if ($same) {
                $ok++;
                $matched[] = "attr:$key";
            } else {
                $bad++;
                $mismatched[] = "attr:$key";
                isset($axes[$key]) && $axisConflicts[] = $key;
            }
        }
        $attr = $ok + $bad > 0 ? $ok / ($ok + $bad) : null;

        foreach ($axisConflicts as $key) {
            $warn[] = 'Similar product with different '.$axes[$key];
        }

        $text = $sim > 0 ? min(1.0, $sim * 1.4) : null; // trigram scores rarely exceed ~0.7 for true matches
        $signals = ['model' => [0.34, $model], 'brand' => [0.18, $brand], 'text' => [0.28, $text], 'category' => [0.10, $category], 'attr' => [0.10, $attr]];
        $weight = array_sum(array_map(fn ($s) => $s[1] === null ? 0 : $s[0], $signals));
        $score = $weight > 0 ? array_sum(array_map(fn ($s) => $s[1] === null ? 0 : $s[0] * $s[1], $signals)) / $weight : 0.0;

        if ($barcodeHit) {
            if ($brand === 0.0 || $model === 0.0) {
                $warn[] = 'Conflicting barcode: it belongs to a different brand or model than the photo shows';

                return $this->candidate($v, self::SAME, 0.7, $matched, $mismatched, $warn);
            }

            return $this->candidate($v, self::EXACT, 1.0, array_merge($matched, ['barcode']), $mismatched, $warn);
        }

        if ($axisConflicts) {
            // The mismatch is already expressed by the "variant" class and its warnings; do not also bury the sibling via the attribute signal.
            $rest = array_diff_key($signals, ['attr' => 1]);
            $w = array_sum(array_map(fn ($s) => $s[1] === null ? 0 : $s[0], $rest));
            $score = $w > 0 ? array_sum(array_map(fn ($s) => $s[1] === null ? 0 : $s[0] * $s[1], $rest)) / $w : $score;
            $score = min($score, 0.79);
            $warn[] = 'Possible product variant';

            return $score >= 0.45 ? $this->candidate($v, self::VARIANT, $score, $matched, $mismatched, array_values(array_unique($warn))) : null;
        }
        if ($model === 0.6) {
            return $this->candidate($v, self::VARIANT, min($score, 0.79), $matched, $mismatched, [...$warn, 'Possible product variant']);
        }
        if ($model === 1.0 && $brand !== 0.0 && $x->conf('model') >= 0.75) {
            return $this->candidate($v, self::EXACT, max($score, 0.9), $matched, $mismatched, $warn);
        }
        if ($model === 1.0 && $brand === 0.0) {
            $warn[] = 'Same model number under a different brand';

            return $this->candidate($v, self::DUPLICATE, min($score, 0.69), $matched, $mismatched, $warn);
        }

        return match (true) {
            $score >= 0.70 => $this->candidate($v, self::SAME, $score, $matched, $mismatched, $warn),
            $score >= 0.50 => $this->candidate($v, self::DUPLICATE, $score, $matched, $mismatched, [...$warn, 'Possible duplicate']),
            default => null,
        };
    }

    /**
     * @param  list<string>  $matched
     * @param  list<string>  $mismatched
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function candidate(ProductVariant $v, string $class, float $score, array $matched, array $mismatched, array $warnings): array
    {
        return [
            'product_id' => $v->product_id, 'variant_id' => $v->id, 'variant' => $v, 'classification' => $class,
            'score' => round($score, 3), 'matched_fields' => $matched, 'mismatched_fields' => $mismatched, 'warnings' => array_values($warnings),
        ];
    }

    /** @return array<string, string> */
    private function axes(): array
    {
        return $this->axes ??= AttributeDefinition::query()->where('is_variant_axis', true)->get()
            ->mapWithKeys(fn ($d) => [$d->key => mb_strtolower($d->label)])->all();
    }
}
