<?php

use App\Models\AiAnalysis;
use App\Models\Category;
use App\Services\AiIntakeService;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\SyncProjector;
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
        ->and($by['Alpha Hardware']['availability']['status'])->toBe('in_stock')->and($by['Bravo Hardware']['availability'])->toBe(['status' => 'low_stock', 'quantity' => null]); // 3 left: low, but the store hides exact counts
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

it('keeps unmoderated candidate products off the marketplace until a platform admin approves them', function () {
    $cand = app(CatalogService::class)->createProduct(['category_id' => Category::where('slug', 'hand-tools')->value('id'), 'name' => 'Unreviewed Mystery Mallet', 'brand' => 'Nobrand'], $this->a['tenant']->id);
    $l = World::stocked($this->a, $cand->variants[0]->id, 5, 20000, publish: false)['listing'];
    $h = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $this->patchJson("/api/v1/vendor/listings/{$l->id}", ['publish' => true, 'expected_row_version' => $l->row_version], $h)->assertStatus(409)->assertJsonPath('error.code', 'product_not_approved');
    expect($l->fresh()->publication_status)->toBe('draft');

    $l->update(['publication_status' => 'published', 'visibility' => 'public']);              // published before publishing was gated
    $this->getJson("/api/v1/public/listings/{$l->id}")->assertNotFound();
    expect(collect($this->getJson('/api/v1/public/stores/'.$this->a['store']->id.'/listings')->json('data'))->pluck('id'))->not->toContain($l->id)
        ->and(json_encode($this->getJson('/api/v1/public/search?q=mallet')->json()))->not->toContain('Mystery')
        ->and(app(SyncProjector::class)->project('public', 'listing', $l->id, null, null))->toBeNull()
        ->and(app(AiIntakeService::class)->publiclyListed()->pluck('id'))->not->toContain($l->variant_id);
    World::customer('cust@test.test');
    $c = World::login($this, 'cust@test.test', 'customer');
    $this->putJson('/api/v1/customer/cart', ['items' => [['listing_id' => $l->id, 'quantity' => 1]]], $c)->assertOk()->assertJsonCount(0, 'data.items');
    $this->postJson('/api/v1/customer/orders', ['items' => [['listing_id' => $l->id, 'quantity' => 1]], 'fulfillment_type' => 'pickup', 'contact_name' => 'C', 'contact_phone' => '09171234567'], $c + idem())
        ->assertStatus(409)->assertJsonPath('error.code', 'listing_unavailable');

    app(CatalogService::class)->approve($cand);
    expect(DB::table('sync_changes')->where('audience', 'public')->where('entity', 'listing')->where('entity_id', $l->id)->exists())->toBeTrue(); // public caches learn it now exists
    $this->getJson("/api/v1/public/listings/{$l->id}")->assertOk()->assertJsonPath('data.id', $l->id);
    expect(app(AiIntakeService::class)->publiclyListed()->pluck('id'))->toContain($l->variant_id);
});

it('offers photo-search candidates only from live stores of active businesses', function () {
    $listed = fn () => app(AiIntakeService::class)->publiclyListed()->pluck('id');
    expect($listed())->toContain($this->la->variant_id);
    $this->a['tenant']->update(['status' => 'suspended']);
    $this->b['store']->update(['is_active' => false]);
    expect($listed())->not->toContain($this->la->variant_id);
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

it('ranks the best matches first and, among equally good ones, the closest seller; nearest_km is the closest store that sells it', function () {
    World::stocked($this->a, $this->refs['BCD001-20V'], 4, 419000); // the drill is only sold in QC; the hammer in QC and Cebu
    $cebu = collect($this->getJson('/api/v1/public/search?lat=10.31&lng=123.88')->assertOk()->json('data'));
    expect($cebu->pluck('name')->all())->toBe(['Claw Hammer 51-188', 'Cordless Drill BCD001'])
        ->and($cebu[0]['nearest_km'])->toBeLessThan(5)->and($cebu[1]['nearest_km'])->toBeGreaterThan(500);
    $nearest = collect($this->getJson('/api/v1/public/search?lat=10.31&lng=123.88&sort=nearest')->json('data'));
    expect($nearest->pluck('name')->first())->toBe('Claw Hammer 51-188');
    $match = collect($this->getJson('/api/v1/public/search?q=cordless%20drill&lat=10.31&lng=123.88')->json('data'));
    expect($match->pluck('name')->first())->toBe('Cordless Drill BCD001'); // a far exact match beats a near non-match
    expect($this->getJson('/api/v1/public/search?q=hammer')->json('data.0'))->not->toHaveKey('nearest_km');
});

it('says when the catalogue knows a product but no registered store sells it', function () {
    $r = $this->getJson('/api/v1/public/search?q=bosch')->assertOk();
    expect($r->json('meta.total'))->toBe(0)->and(collect($r->json('meta_extra.unlisted_matches'))->pluck('brand')->unique()->all())->toBe(['Bosch']);
    expect($this->getJson('/api/v1/public/search?q=hammer')->json('meta_extra.unlisted_matches'))->toBe([]);
    expect($this->getJson('/api/v1/public/search?q=zzzqqq')->json('meta_extra.unlisted_matches'))->toBe([]);
});

it('supports a detailed search: brand, model, price range, spec, distance and fulfilment must all hold', function () {
    World::stocked($this->a, $this->refs['BCD001-20V'], 4, 419000);
    $cheap = World::stocked($this->b, $this->refs['BCD001-12V'], 4, 289000)['listing'];
    $this->svc->updateListing($this->b['ctx'], $cheap->id, ['delivery_available' => false]);
    $names = fn (string $qs) => collect($this->getJson('/api/v1/public/search?'.$qs)->assertOk()->json('data'))->pluck('name')->all();
    expect($names('brand=black%2Bdecker'))->toBe(['Cordless Drill BCD001'])
        ->and($names('model=bcd-001'))->toBe(['Cordless Drill BCD001'])
        ->and($names('model=51188'))->toBe(['Claw Hammer 51-188'])
        ->and($names('max_price_cents=300000&sort=price_asc'))->toBe(['Claw Hammer 51-188', 'Cordless Drill BCD001'])
        ->and($names('min_price_cents=300000'))->toBe(['Cordless Drill BCD001'])
        ->and($names('spec[voltage_v]=12'))->toBe(['Cordless Drill BCD001'])
        ->and($names('spec[voltage_v]=24'))->toBe([])
        ->and($names('spec[weight_oz]=16'))->toBe(['Claw Hammer 51-188']);
    // same listing must satisfy all listing-level filters: the cheap 12V drill is in Cebu, the 20V one in QC
    expect($names('model=bcd001&max_price_cents=300000&lat=14.63&lng=121.03&max_km=25'))->toBe([])
        ->and($names('model=bcd001&max_price_cents=300000&lat=10.31&lng=123.88&max_km=25'))->toBe(['Cordless Drill BCD001'])
        ->and($names('model=bcd001&fulfillment=delivery&lat=10.31&lng=123.88&max_km=25'))->toBe([]);
    // the price range and store count describe the listings that matched, not every listing of the product
    $capped = $this->getJson('/api/v1/public/search?model=bcd001&max_price_cents=300000')->json('data.0');
    expect($capped)->toMatchArray(['min_price_cents' => 289000, 'max_price_cents' => 289000, 'store_count' => 1]);
    expect($this->getJson('/api/v1/public/search?model=bcd001')->json('data.0'))->toMatchArray(['min_price_cents' => 289000, 'max_price_cents' => 419000, 'store_count' => 2]);
    $this->getJson('/api/v1/public/search?max_km=5')->assertStatus(422);
    $this->getJson('/api/v1/public/search?min_price_cents=500&max_price_cents=100')->assertStatus(422);
});

it('offers detailed-search choices from what stores actually list', function () {
    World::stocked($this->a, $this->refs['BCD001-20V'], 4, 419000);
    $f = $this->getJson('/api/v1/public/search/filters')->assertOk()->json('data');
    expect($f['brands'])->toBe(['Black+Decker', 'Stanley']);
    $specs = collect($f['specs'])->keyBy('key');
    expect($specs['voltage_v'])->toMatchArray(['label' => 'Voltage', 'unit' => 'V', 'values' => ['20']])->and($specs['weight_oz']['values'])->toBe(['16']);
    $hand = $this->getJson('/api/v1/public/search/filters?category=hand-tools')->json('data');
    expect($hand['brands'])->toBe(['Stanley'])->and(collect($hand['specs'])->pluck('key')->all())->toBe(['weight_oz']);
});

it('lists public stores with live listing counts, nearest first, and filters by name or area', function () {
    $names = fn ($r) => collect($r->json('data'))->pluck('name')->all();
    $near = $this->getJson('/api/v1/public/stores?lat=10.31&lng=123.88')->assertOk();
    expect($names($near))->toBe(['Bravo Cebu', 'Alpha QC'])->and($near->json('data.0.distance_km'))->toBeLessThan(5)->and($near->json('data.1.distance_km'))->toBeGreaterThan(500)
        ->and($near->json('data.0.listing_count'))->toBe(1)->and($near->json('data.0.business_name'))->toBe('Bravo Hardware');
    expect($names($this->getJson('/api/v1/public/stores')))->toBe(['Alpha QC', 'Bravo Cebu']); // no location: by business name
    expect($names($this->getJson('/api/v1/public/stores?q=cebu')))->toBe(['Bravo Cebu']);
    expect($names($this->getJson('/api/v1/public/stores?q=Alpha%20Hardware')))->toBe(['Alpha QC']);
    expect($names($this->getJson('/api/v1/public/stores?q=%25')))->toBe([]); // LIKE wildcards are literal
    $this->getJson('/api/v1/public/stores?lat=14.6')->assertStatus(422);

    $this->svc->updateListing($this->b['ctx'], $this->lb->id, ['publish' => false]);
    expect($this->getJson('/api/v1/public/stores?q=cebu')->json('data.0.listing_count'))->toBe(0); // the store stays, its count drops
    $this->a['store']->update(['is_active' => false]);
    $this->b['tenant']->update(['status' => 'suspended']);
    expect($names($this->getJson('/api/v1/public/stores')))->toBe([]);
    expect(json_encode($this->getJson('/api/v1/public/stores')->json()))->not->toContain('owner@');
});

it('searches within one store and returns product images with its listings', function () {
    World::stocked($this->a, $this->refs['BCD001-20V'], 4, 419000);
    $all = $this->getJson('/api/v1/public/stores/'.$this->a['store']->id.'/listings')->assertOk();
    expect($all->json('data'))->toHaveCount(2)->and($all->json('data.0'))->toHaveKey('image_url');
    $hit = collect($this->getJson('/api/v1/public/stores/'.$this->a['store']->id.'/listings?q=drill')->json('data'));
    expect($hit)->toHaveCount(1)->and($hit->first()['price_cents'])->toBe(419000);
    expect($this->getJson('/api/v1/public/stores/'.$this->a['store']->id.'/listings?q=BCD001')->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/v1/public/stores/'.$this->a['store']->id.'/listings?q=zzz')->json('data'))->toBe([]);
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
