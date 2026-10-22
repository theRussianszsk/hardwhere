<?php

use App\Ai\ProductExtraction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CatalogMatcher;
use App\Services\CatalogService;
use App\Support\Text;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Support\World;

function extraction(array $o = []): ProductExtraction
{
    $o += ['name' => null, 'brand' => null, 'model' => null, 'part' => null, 'category' => null, 'attrs' => [], 'pack' => null, 'barcode' => null,
        'conf' => ['name' => 0.9, 'brand' => 0.9, 'model' => 0.9, 'category' => 0.9], 'overall' => 0.85, 'sufficient' => true];

    return new ProductExtraction($o['name'], $o['brand'], $o['model'], $o['part'], $o['category'], $o['attrs'], $o['pack'], $o['barcode'], $o['conf'], $o['overall'], $o['sufficient']);
}

beforeEach(function () {
    $this->refs = World::catalog();
    $this->m = app(CatalogMatcher::class);
    $this->top = fn (array $r, int $i = 0) => $r['candidates'][$i] ?? null;
    $this->find = fn (array $r, string $ref) => collect($r['candidates'])->firstWhere('variant_id', $this->refs[$ref]);
});

it('matches an exact barcode with certainty and needs no confirmation when nothing is suspicious', function () {
    $r = $this->m->match(extraction(['barcode' => '4800000100017', 'brand' => 'Stanley', 'name' => 'Claw hammer', 'model' => '51-188']));
    expect($r['classification'])->toBe('exact_match')->and(($this->top)($r)['variant_id'])->toBe($this->refs['STH16'])->and(($this->top)($r)['score'])->toBe(1.0)
        ->and(($this->top)($r)['matched_fields'])->toContain('barcode')->and($r['warnings'])->toBe([])->and($r['requires_confirmation'])->toBeFalse();
});

it('flags a barcode that contradicts the visible brand/model', function () {
    $r = $this->m->match(extraction(['barcode' => '4800000100017', 'brand' => 'Tolsen', 'name' => 'Claw hammer', 'model' => '25101']));
    expect(implode(' ', $r['warnings']))->toContain('Conflicting barcode')->and($r['requires_confirmation'])->toBeTrue()
        ->and(collect($r['candidates'])->pluck('classification')->unique()->all())->not->toContain('exact_match') // contradictory evidence: nothing is "exact"
        ->and(collect($r['candidates'])->pluck('variant_id')->all())->toContain($this->refs['STH16'], $this->refs['TL25101']);
});

it('matches manufacturer + model exactly and keeps the sibling model as a variant, never the same product', function () {
    $r = $this->m->match(extraction(['brand' => 'Makita', 'name' => 'Hammer drill', 'model' => 'HP1630', 'attrs' => ['voltage_v' => 220]]));
    $exact = ($this->find)($r, 'HP1630');
    $kit = ($this->find)($r, 'HP1630K');
    expect($exact['classification'])->toBe('exact_match')->and($exact['matched_fields'])->toContain('model', 'brand')
        ->and($kit['classification'])->toBe('likely_variant')->and(implode(' ', $kit['warnings']))->toContain('Possible product variant')
        ->and(($this->top)($r)['variant_id'])->toBe($this->refs['HP1630']);
});

it('ignores model formatting differences', function () {
    expect(Text::key('HP 1630') === Text::key('hp-1630'))->toBeTrue();
    $r = $this->m->match(extraction(['brand' => 'makita', 'model' => 'hp 1630k']));
    expect(($this->top)($r)['variant_id'])->toBe($this->refs['HP1630K'])->and(($this->top)($r)['classification'])->toBe('exact_match');
});

it('matches SKU / part numbers through variant and alias data', function () {
    $r = $this->m->match(extraction(['model' => null, 'part' => 'CB-440', 'name' => 'carbon brush']));
    expect(($this->top)($r)['variant_id'])->toBe($this->refs['CB440']);
});

it('separates variants that differ by voltage', function () {
    $r = $this->m->match(extraction(['brand' => 'Black+Decker', 'model' => 'BCD001', 'name' => 'Cordless drill', 'attrs' => ['voltage_v' => 12]]));
    $twelve = ($this->find)($r, 'BCD001-12V');
    $twenty = ($this->find)($r, 'BCD001-20V');
    expect(($this->top)($r)['variant_id'])->toBe($this->refs['BCD001-12V'])->and($twelve['classification'])->toBe('exact_match')
        ->and($twenty['classification'])->toBe('likely_variant')->and($twenty['warnings'])->toContain('Similar product with different voltage')->and($twenty['mismatched_fields'])->toContain('attr:voltage_v');
});

it('separates variants that differ by pack quantity, size, capacity, and color', function () {
    $pack = $this->m->match(extraction(['name' => 'Tek screw self-drilling', 'attrs' => ['size' => '#10 x 1"'], 'pack' => 500, 'conf' => ['name' => 0.8], 'category' => 'Fasteners']));
    expect(($this->top)($pack)['variant_id'])->toBe($this->refs['TEK500'])->and(($this->find)($pack, 'TEK100')['warnings'])->toContain('Similar product with different pack quantity');

    $paint = $this->m->match(extraction(['brand' => 'Boysen', 'name' => 'Permacoat flat latex white', 'attrs' => ['capacity_l' => 4, 'color' => 'white']]));
    expect(($this->top)($paint)['variant_id'])->toBe($this->refs['BOY4L'])->and(($this->find)($paint, 'BOY16L')['classification'])->toBe('likely_variant')
        ->and(implode(' ', ($this->find)($paint, 'BOY16L')['warnings']))->toContain('different capacity');

    $led = $this->m->match(extraction(['brand' => 'Philips', 'name' => 'LED bulb', 'attrs' => ['wattage_w' => 9, 'color' => 'daylight']]));
    expect(($this->top)($led)['variant_id'])->toBe($this->refs['LED9WD'])->and(($this->find)($led, 'LED9WW')['warnings'])->toContain('Similar product with different color');
});

it('uses text similarity and attributes when there is no brand, model, or barcode', function () {
    $r = $this->m->match(extraction(['name' => 'claw hammer', 'attrs' => ['weight_oz' => 16], 'category' => 'Hand Tools', 'conf' => ['name' => 0.8, 'category' => 0.8], 'overall' => 0.7]));
    // Two brands make a 16oz claw hammer: both are offered for a human to choose, and the 20oz sibling is a variant, not a match.
    expect(collect($r['candidates'])->take(2)->pluck('variant_id')->sort()->values()->all())->toBe(collect([$this->refs['STH16'], $this->refs['TL25101']])->sort()->values()->all())
        ->and(($this->find)($r, 'STH20')['classification'] ?? null)->toBe('likely_variant')->and($r['requires_confirmation'])->toBeTrue();
});

it('requires a human decision for anything ambiguous and warns on low-confidence brand or model', function () {
    $r = $this->m->match(extraction(['brand' => 'Black+Decker', 'model' => 'BCD001', 'name' => 'Cordless drill', 'attrs' => ['voltage_v' => 20], 'conf' => ['name' => 0.8, 'brand' => 0.45, 'model' => 0.5, 'category' => 0.9], 'overall' => 0.62]));
    expect($r['requires_confirmation'])->toBeTrue()->and($r['warnings'])->toContain('Low-confidence brand', 'Low-confidence model')->and($r['classification'])->not->toBe('exact_match');
});

it('suggests a new product candidate when nothing in the catalog is close', function () {
    $r = $this->m->match(extraction(['name' => 'Expanding foam sealant 750ml', 'brand' => 'Kingfoam', 'model' => 'KF-750', 'category' => 'Building Supplies', 'attrs' => ['capacity_ml' => 750]]));
    expect($r['classification'])->toBe('new_product_candidate')->and($r['candidates'])->toBe([])->and($r['requires_confirmation'])->toBeTrue();
});

it('reports insufficient confidence for unreadable images instead of guessing', function () {
    $r = $this->m->match(extraction(['conf' => ['name' => 0.1], 'overall' => 0.12, 'sufficient' => false]));
    expect($r['classification'])->toBe('insufficient_confidence')->and($r['warnings'])->toContain('Image insufficient for reliable identification');
});

it('flags the same model number under a different brand as a possible duplicate, not a match', function () {
    $r = $this->m->match(extraction(['brand' => 'Bosch', 'model' => 'HP1630', 'name' => 'Hammer drill']));
    $c = ($this->find)($r, 'HP1630');
    expect($c['classification'])->toBe('possible_duplicate')->and($c['warnings'])->toContain('Same model number under a different brand');
});

it('can be restricted to a subset of variants (what a tenant carries)', function () {
    $restrict = ProductVariant::query()->whereIn('product_variants.id', [$this->refs['HP1630K']]);
    $r = $this->m->match(extraction(['brand' => 'Makita', 'model' => 'HP1630']), null, $restrict);
    expect(collect($r['candidates'])->pluck('variant_id')->all())->toBe([$this->refs['HP1630K']]);
});

it('shows a tenant its own candidates but never other tenants\'', function () {
    $a = World::tenant('alpha');
    $b = World::tenant('beta');
    $cat = Category::first();
    app(CatalogService::class)->createProduct(['category_id' => $cat->id, 'brand' => 'Zorro', 'name' => 'Zorro Quantum Widget', 'model_number' => 'ZQW-9', 'variants' => [['name' => 'ZQW-9', 'model_number' => 'ZQW-9']]], $a['tenant']->id);
    $x = extraction(['brand' => 'Zorro', 'model' => 'ZQW-9', 'name' => 'Quantum Widget']);
    expect($this->m->match($x, $a['tenant']->id)['candidates'])->not->toBe([])
        ->and($this->m->match($x, $b['tenant']->id)['candidates'])->toBe([])
        ->and($this->m->match($x)['candidates'])->toBe([]);
});

it('keeps canonical products with materially different attributes as distinct variants in the catalog', function () {
    $p = Product::query()->where('brand', 'Philips')->first();
    expect($p->variants)->toHaveCount(2)->and($p->variants->pluck('variant_key')->unique())->toHaveCount(2);
    expect(fn () => app(CatalogService::class)->createVariant($p, ['name' => 'dup', 'attributes' => ['wattage_w' => 9, 'color' => 'daylight']]))->toThrow(UniqueConstraintViolationException::class);
});
