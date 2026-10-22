<?php

use App\Models\AiAnalysis;
use App\Services\InventoryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

beforeEach(function () {
    $this->refs = World::catalog();
    $this->a = World::tenant('alpha', ['name' => 'Alpha QC', 'lat' => 14.6285, 'lng' => 121.0366]);
    $this->b = World::tenant('bravo', ['name' => 'Bravo Cebu', 'lat' => 10.3157, 'lng' => 123.8854, 'city' => 'Cebu City']);
    $this->la = World::stocked($this->a, $this->refs['STH16'], 20, 35000)['listing'];
    $this->lb = World::stocked($this->b, $this->refs['STH16'], 3, 32900)['listing'];
    $this->product = $this->la->product_id;
    $this->svc = app(InventoryService::class);
});

it('shows the same canonical product with each tenant\'s own price, SKU and availability', function () {
    $r = $this->getJson("/api/v1/public/products/{$this->product}/listings")->assertOk();
    $by = collect($r->json('data'))->keyBy(fn ($l) => $l['store']['business_name']);
    expect($by)->toHaveCount(2)->and($by['Alpha Hardware']['price_cents'])->toBe(35000)->and($by['Bravo Hardware']['price_cents'])->toBe(32900)
        ->and($by['Alpha Hardware']['sku'])->not->toBe($by['Bravo Hardware']['sku'])
        ->and($by['Alpha Hardware']['availability']['status'])->toBe('in_stock')->and($by['Bravo Hardware']['availability'])->toBe(['status' => 'low_stock', 'quantity' => 3]);
});

it('never exposes cost, margin, supplier, staff, or exact private stock', function () {
    $json = json_encode([
        $this->getJson("/api/v1/public/products/{$this->product}/listings")->json(), $this->getJson('/api/v1/public/search?q=hammer')->json(),
        $this->getJson('/api/v1/public/listings/'.$this->la->id)->json(), $this->getJson('/api/v1/public/stores/'.$this->a['store']->id)->json(), $this->getJson('/api/v1/public/stores/'.$this->a['store']->id.'/listings')->json(),
    ]);
    foreach (['cost', 'margin', 'supplier', 'reorder', 'on_hand', 'reserved', 'stock_buffer', 'unit_cost', 'owner@alpha'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    expect($this->getJson('/api/v1/public/listings/'.$this->la->id)->json('data.availability.quantity'))->toBeNull(); // 20 in stock, exact count hidden
});

it('reveals exact stock only when the store opts in', function () {
    $this->svc->updateListing($this->a['ctx'], $this->la->id, ['show_exact_stock' => true]);
    expect($this->getJson('/api/v1/public/listings/'.$this->la->id)->json('data.availability'))->toBe(['status' => 'in_stock', 'quantity' => 20]);
});

it('hides anything that is not published and public', function () {
    $count = fn () => collect($this->getJson("/api/v1/public/products/{$this->product}/listings")->json('data'))->count();
    expect($count())->toBe(2);

    $this->svc->updateListing($this->b['ctx'], $this->lb->id, ['publish' => false]);          // unpublished
    expect($count())->toBe(1);
    $this->getJson('/api/v1/public/listings/'.$this->lb->id)->assertNotFound();

    $this->la->update(['visibility' => 'hidden']);                                            // published but hidden
    expect($count())->toBe(0);
    $this->getJson('/api/v1/public/search?q=hammer')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/public/products/{$this->product}")->assertOk(); // the canonical product itself is still public, just unsold

    $this->la->update(['visibility' => 'public']);
    $this->a['store']->update(['is_active' => false]);                                         // closed store
    expect($count())->toBe(0);
    $this->getJson('/api/v1/public/stores/'.$this->a['store']->id)->assertNotFound();
    $this->a['store']->update(['is_active' => true]);
    $this->a['tenant']->update(['status' => 'suspended']);                                     // suspended business
    expect($count())->toBe(0);
    $this->a['tenant']->update(['status' => 'active']);
    expect($count())->toBe(1);
    $this->la->delete();                                                                      // soft deleted
    expect($count())->toBe(0);
});

it('searches by text, brand, model, sku, barcode, synonym, and category', function () {
    $other = World::stocked($this->a, $this->refs['BCD001-20V'], 4, 419000);
    $ids = fn (string $q) => collect($this->getJson('/api/v1/public/search?'.http_build_query(['q' => $q]))->assertOk()->json('data'))->pluck('id')->all();
    $hammer = $this->product;
    $drill = $other['listing']->product_id;

    expect($ids('claw hammer'))->toContain($hammer)->not->toContain($drill)
        ->and($ids('stanley'))->toContain($hammer)
        ->and($ids('51-188'))->toContain($hammer)
        ->and($ids('51188'))->toContain($hammer)
        ->and($ids('martilyo'))->toContain($hammer)                    // Filipino synonym
        ->and($ids('4800000100017'))->toContain($hammer)               // barcode
        ->and($ids(strtolower($this->la->tenant_sku)))->toContain($hammer) // a store's SKU
        ->and($ids('BCD001'))->toBe([$drill])
        ->and($ids('zzzz nothing'))->toBe([]);
    $cat = collect($this->getJson('/api/v1/public/search?category=hand-tools')->json('data'))->pluck('id')->all();
    expect($cat)->toBe([$hammer]);
    expect(collect($this->getJson('/api/v1/public/search?brand=stanley')->json('data'))->pluck('id')->all())->toBe([$hammer]);
});

it('understands natural-language specs and price limits', function () {
    $b20 = World::stocked($this->a, $this->refs['BCD001-20V'], 4, 419000, sku: 'BD-20')['listing'];
    World::stocked($this->a, $this->refs['BCD001-12V'], 4, 329000, sku: 'BD-12');
    $ids = fn (string $q) => collect($this->getJson('/api/v1/public/search?'.http_build_query(['q' => $q]))->assertOk()->json('data'))->pluck('id')->all();

    $both = $ids('cordless drill');
    expect($both)->toContain($b20->product_id);
    $products = $this->getJson('/api/v1/public/search?q='.urlencode('cordless drill under 5000'))->json('data.0');
    expect($products['max_price_cents'])->toBeLessThanOrEqual(500000);
    expect($ids('cordless drill under 3000'))->toBe([]);                  // cheapest is 3290.00
    expect($ids('20V cordless drill'))->toBe([$b20->product_id]);         // product has a 20V variant
    expect($ids('50V cordless drill'))->toBe([]);                          // no such variant
    expect($ids('cordless drill 20V under 5000 pesos'))->toBe([$b20->product_id]);
});

it('sorts and paginates, and reports price ranges and store counts', function () {
    World::stocked($this->a, $this->refs['TLPL8'], 5, 29500);
    World::stocked($this->a, $this->refs['NAIL3'], 50, 8500);
    $asc = collect($this->getJson('/api/v1/public/search?sort=price_asc')->json('data'));
    expect($asc->pluck('min_price_cents')->all())->toBe($asc->pluck('min_price_cents')->sort()->values()->all());
    $hammer = $asc->firstWhere('id', $this->product);
    expect($hammer['min_price_cents'])->toBe(32900)->and($hammer['max_price_cents'])->toBe(35000)->and($hammer['store_count'])->toBe(2);
    $page = $this->getJson('/api/v1/public/search?per_page=2&page=2')->assertOk();
    expect($page->json('meta.per_page'))->toBe(2)->and($page->json('meta.current_page'))->toBe(2)->and($page->json('meta.total'))->toBe(3);
    $this->getJson('/api/v1/public/search?sort=bogus')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $instock = collect($this->getJson('/api/v1/public/search?in_stock=1')->json('data'))->count();
    expect($instock)->toBe(3);
});

it('orders the store comparison by distance and gives contact + directions', function () {
    $near = collect($this->getJson("/api/v1/public/products/{$this->product}/listings?lat=14.60&lng=121.00")->json('data'));
    expect($near->first()['store']['business_name'])->toBe('Alpha Hardware')->and($near->first()['distance_km'])->toBeLessThan(10)->and($near->last()['distance_km'])->toBeGreaterThan(500);
    $far = collect($this->getJson("/api/v1/public/products/{$this->product}/listings?lat=10.31&lng=123.88")->json('data'));
    expect($far->first()['store']['business_name'])->toBe('Bravo Hardware');
    $store = $this->getJson('/api/v1/public/stores/'.$this->a['store']->id)->assertOk()->json('data');
    expect($store)->toMatchArray(['name' => 'Alpha QC', 'phone' => '+6328000000', 'city' => 'Quezon City'])->and($store['directions_url'])->toContain('destination=14.6285,121.0366');
});

it('keeps unlisted catalog products out of search and returns 404 for unknown products', function () {
    $this->getJson('/api/v1/public/products/999999')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    expect(json_encode($this->getJson('/api/v1/public/search?q=Bosch')->json('data')))->toBe('[]'); // in the catalog, but nobody sells it
});

describe('customer-only features', function () {
    beforeEach(function () {
        World::customer('cust@test.test');
        $this->c = World::login($this, 'cust@test.test', 'customer');
    });

    it('requires a signed-in customer for photo search and returns labelled candidates limited to products on sale', function () {
        $img = fn () => UploadedFile::fake()->image('x.jpg', 600, 600);
        $this->post('/api/v1/customer/search/photo', ['image' => $img()], idem() + ['Accept' => 'application/json'])->assertUnauthorized();

        $ok = $this->post('/api/v1/customer/search/photo', ['image' => $img(), 'hint' => 'barcode-visible'], $this->c + idem() + ['Accept' => 'application/json'])->assertOk();
        expect($ok->json('data.is_mock'))->toBeTrue()->and($ok->json('data.candidates.0.product.id'))->toBe($this->product)->and($ok->json('meta.server_time'))->not->toBeEmpty();

        // HP1630 exists in the catalog but nobody lists it -> not offered to customers
        $none = $this->post('/api/v1/customer/search/photo', ['image' => $img(), 'hint' => 'makita-hp1630'], $this->c + idem() + ['Accept' => 'application/json'])->assertOk();
        expect($none->json('data.candidates'))->toBe([]);
        $this->post('/api/v1/customer/search/photo', ['image' => $img(), 'hint' => 'blurry'], $this->c + idem() + ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'image_insufficient');
        expect(AiAnalysis::query()->whereNotNull('image_path')->count())->toBe(0); // customer photos are not retained
    });

    it('keeps a server cart that holds no stock and drops listings that stop being available', function () {
        $this->putJson('/api/v1/customer/cart', ['items' => [['listing_id' => $this->la->id, 'quantity' => 2], ['listing_id' => $this->lb->id, 'quantity' => 1]]], $this->c)->assertOk()->assertJsonCount(2, 'data.items');
        expect(DB::table('stock_snapshots')->where('inventory_item_id', $this->la->inventory_item_id)->value('reserved'))->toBe(0);
        $this->svc->updateListing($this->b['ctx'], $this->lb->id, ['publish' => false]);
        $cart = $this->getJson('/api/v1/customer/cart', $this->c)->assertOk()->json('data.items');
        expect(collect($cart)->firstWhere('listing_id', $this->lb->id)['listing'])->toBeNull()->and(collect($cart)->firstWhere('listing_id', $this->la->id)['listing']['price_cents'])->toBe(35000);
        $this->putJson('/api/v1/customer/cart', ['items' => [['listing_id' => $this->lb->id, 'quantity' => 1]]], $this->c)->assertOk()->assertJsonCount(0, 'data.items');
        $this->putJson('/api/v1/customer/cart', ['items' => [['listing_id' => $this->la->id, 'quantity' => 0]]], $this->c)->assertStatus(422);
        $this->deleteJson('/api/v1/customer/cart', [], $this->c)->assertOk();
    });

    it('clears ordered lines from the cart after a successful order', function () {
        $this->putJson('/api/v1/customer/cart', ['items' => [['listing_id' => $this->la->id, 'quantity' => 2]]], $this->c);
        $this->postJson('/api/v1/customer/orders', ['items' => [['listing_id' => $this->la->id, 'quantity' => 2]], 'fulfillment_type' => 'pickup', 'contact_name' => 'C', 'contact_phone' => '09171234567'], $this->c + idem())->assertCreated();
        $this->getJson('/api/v1/customer/cart', $this->c)->assertJsonCount(0, 'data.items');
    });
});
