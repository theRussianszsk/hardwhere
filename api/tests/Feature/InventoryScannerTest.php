<?php

use Illuminate\Http\UploadedFile;
use Tests\Support\World;

/** The flows behind public/inventory.html: recognise a photo, then either find the stocked item or take it in as new inventory. */
beforeEach(function () {
    $this->refs = World::catalog();
    $this->w = World::tenant('scan');
    $this->h = World::login($this, 'owner@scan.test', 'vendor', 'scan');
});

it('filters inventory to the variants a recognition returned', function () {
    $drill = World::stocked($this->w, $this->refs['HP1630'], 3, publish: false);
    World::stocked($this->w, $this->refs['STH16'], 7, publish: false);

    $items = $this->getJson('/api/v1/vendor/inventory?variant_ids='.$this->refs['HP1630'].','.$this->refs['GSB13'], $this->h)->assertOk()->json('data');

    expect($items)->toHaveCount(1)
        ->and($items[0]['id'])->toBe($drill['item']->id)
        ->and($items[0]['on_hand'])->toBe(3);
});

it('ignores junk in variant_ids and never leaks another tenant\'s items', function () {
    $other = World::tenant('other');
    World::stocked($other, $this->refs['HP1630'], 9, publish: false);

    $this->getJson('/api/v1/vendor/inventory?variant_ids='.$this->refs['HP1630'], $this->h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/vendor/inventory?variant_ids=abc,,-', $this->h)->assertOk();
});

it('recognises a stocked product from a photo and adjusts its stock', function () {
    $drill = World::stocked($this->w, $this->refs['HP1630'], 2, publish: false);

    $a = $this->post('/api/v1/vendor/ai/analyses', ['image' => UploadedFile::fake()->image('cam.jpg', 640, 480), 'hint' => 'makita-hp1630'], $this->h + idem('scan-a') + ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    $variantIds = collect($a['candidates'])->pluck('variant.id')->filter()->implode(',');

    $stocked = $this->getJson("/api/v1/vendor/inventory?variant_ids=$variantIds", $this->h)->assertOk()->json('data');
    expect(collect($stocked)->pluck('id')->all())->toBe([$drill['item']->id]);

    $this->postJson("/api/v1/vendor/inventory/{$drill['item']->id}/adjustments", ['location_id' => $this->w['location']->id, 'movement_type' => 'purchase_receipt', 'quantity_delta' => 4], $this->h + idem('scan-adj'))
        ->assertCreated()->assertJsonPath('data.after_quantity', 6);
});
