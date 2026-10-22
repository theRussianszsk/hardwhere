<?php

use App\Ai\ProductExtraction;
use App\Services\CatalogMatcher;
use Illuminate\Http\UploadedFile;
use Tests\Support\World;

/** A photo of something we do not sell must say so, not offer the closest catalog product. */
beforeEach(function () {
    $this->refs = World::catalog();
    $this->w = World::tenant('nohw');
    $this->h = World::login($this, 'owner@nohw.test', 'vendor', 'nohw');
});

$extraction = fn (array $raw) => ProductExtraction::fromModelOutput($raw + ['confidence' => ['name' => 0.9, 'brand' => 0.9, 'model' => 0.9, 'category' => 0.9], 'overall_confidence' => 0.9]);

it('classifies a non-hardware photo as not_hardware with no candidates', function () {
    World::stocked($this->w, $this->refs['STH16'], 5, publish: false);

    $a = $this->post('/api/v1/vendor/ai/analyses', ['image' => UploadedFile::fake()->image('noodles.jpg', 640, 480), 'hint' => 'not-hardware'], $this->h + idem('nohw-a') + ['Accept' => 'application/json'])
        ->assertCreated()->json('data');

    expect($a['classification'])->toBe('not_hardware')
        ->and($a['candidates'])->toBe([])
        ->and($a['extracted']['is_hardware'])->toBeFalse()
        ->and($a['warnings'])->toContain('Not a hardware product');
});

it('reads the not-hardware signal from the flag or the note', function () use ($extraction) {
    expect($extraction(['name' => 'Cola', 'is_hardware' => false])->isHardware)->toBeFalse()
        ->and($extraction(['name' => 'Cola', 'is_hardware' => 'false'])->isHardware)->toBeFalse()
        ->and($extraction(['name' => 'Cola', 'notes' => ['Not a hardware product']])->isHardware)->toBeFalse()
        ->and($extraction(['name' => 'Claw hammer'])->isHardware)->toBeTrue()
        ->and($extraction(['name' => 'Claw hammer', 'is_hardware' => 'maybe'])->isHardware)->toBeTrue();
});

it('does not offer a catalog product that only shares a word with the photo', function () use ($extraction) {
    // "Hammer" overlaps the Stanley hammers by text, but brand, model and category all disagree.
    $m = app(CatalogMatcher::class)->match($extraction(['name' => 'Toy hammer squeaky plastic', 'brand' => 'Fisher-Price', 'category' => 'Toys']));

    expect($m['candidates'])->toBe([])->and($m['classification'])->toBe('new_product_candidate');
});

it('offers same-type catalog products for an unbranded item, but only as possible matches', function () use ($extraction) {
    // Live DeepSeek returned exactly this for a photo of a generic claw hammer.
    $m = app(CatalogMatcher::class)->match($extraction(['name' => 'Claw Hammer', 'category' => 'Hand Tools']));

    $classes = collect($m['candidates'])->pluck('classification')->unique()->values()->all();
    expect($m['candidates'])->not->toBe([])
        ->and($classes)->toBe(['possible_duplicate'])
        ->and(collect($m['candidates'])->pluck('variant_id'))->toContain($this->refs['STH16'])
        ->and($m['candidates'][0]['warnings'])->toContain('Same product type; brand and model not confirmed');
    // the hammer drill shares a word, not the product type
    expect(collect($m['candidates'])->pluck('variant_id'))->not->toContain($this->refs['HP1630']);
});

it('still matches a real product by brand and model', function () use ($extraction) {
    $m = app(CatalogMatcher::class)->match($extraction(['name' => 'Claw Hammer 16oz', 'brand' => 'Stanley', 'model' => '51-188', 'category' => 'Hand Tools']));

    expect($m['classification'])->toBe('exact_match')->and($m['candidates'][0]['variant_id'])->toBe($this->refs['STH16']);
});
