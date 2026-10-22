<?php

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\StockLedgerEntry;
use App\Models\StockSnapshot;
use App\Models\Store;
use App\Models\TenantListing;
use App\Models\TenantProductMapping;
use Illuminate\Http\UploadedFile;
use Tests\Support\World;

beforeEach(function () {
    $this->refs = World::catalog();
    $this->w = World::tenant('alpha');
    $this->s = World::stocked($this->w, $this->refs['STH16'], 10, 35000, sku: 'HAM-16');
    $this->store2 = Store::create(['tenant_id' => $this->w['tenant']->id, 'name' => 'Antipolo', 'code' => 'ALP-2', 'address_line' => '2 Test St', 'city' => 'Antipolo',
        'province' => 'Rizal', 'lat' => 14.59, 'lng' => 121.18, 'phone' => '+6328000001', 'supports_pickup' => true, 'supports_delivery' => false]);
    $this->loc2 = InventoryLocation::create(['tenant_id' => $this->w['tenant']->id, 'store_id' => $this->store2->id, 'name' => 'Main floor', 'code' => 'MAIN']);
    $this->owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $this->body = fn (array $x = []) => $x + ['variant_id' => $this->refs['STH16'], 'tenant_sku' => 'HAM-16-ANT', 'cost_cents' => 99, 'price_cents' => 36000, 'quantity' => 4, 'reorder_threshold' => 7, 'store_id' => $this->store2->id];
});

it('stocks an item the tenant already carries at a second store: its own listing and opening balance on the same item', function () {
    $r = $this->postJson('/api/v1/vendor/inventory', ($this->body)(), $this->owner + idem('s2'))->assertCreated();
    expect($r->json('data.id'))->toBe($this->s['item']->id)->and($r->json('data.sku'))->toBe('HAM-16')->and($r->json('data.on_hand'))->toBe(14);

    $item = InventoryItem::query()->forTenant($this->w['ctx'])->sole()->fresh();
    expect([$item->tenant_sku, $item->cost_cents, $item->reorder_threshold, $item->default_location_id])->toBe(['HAM-16', 21000, 2, $this->w['location']->id]); // tenant-wide fields untouched
    $listing = TenantListing::query()->forTenant($this->w['ctx'])->where('store_id', $this->store2->id)->sole();
    expect([$listing->inventory_item_id, $listing->tenant_sku, $listing->price_cents, $listing->publication_status, $listing->visibility])->toBe([$item->id, 'HAM-16', 36000, 'draft', 'hidden']);
    expect(StockSnapshot::query()->forTenant($this->w['ctx'])->where('location_id', $this->loc2->id)->sole()->on_hand)->toBe(4)
        ->and(StockSnapshot::query()->forTenant($this->w['ctx'])->where('location_id', $this->w['location']->id)->sole()->on_hand)->toBe(10);
    $opening = StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('location_id', $this->loc2->id)->sole();
    expect([$opening->movement_type, $opening->quantity_delta])->toBe(['opening_balance', 4]);
    expect(TenantProductMapping::query()->where('inventory_item_id', $item->id)->count())->toBe(1);

    // Only a store that already sells it is refused, and nothing is written.
    $this->postJson('/api/v1/vendor/inventory', ($this->body)(), $this->owner + idem('s2-again'))->assertStatus(409)->assertJsonPath('error.code', 'already_stocked')->assertJsonPath('error.details.inventory_item_id', $item->id);
    $this->postJson('/api/v1/vendor/inventory', ($this->body)(['store_id' => $this->w['store']->id]), $this->owner + idem('s1-again'))->assertStatus(409)->assertJsonPath('error.code', 'already_stocked');
    expect(TenantListing::query()->forTenant($this->w['ctx'])->count())->toBe(2)->and(StockSnapshot::query()->forTenant($this->w['ctx'])->sum('on_hand'))->toBe(14);
});

it('lets the second store sell it at its own price from its own stock', function () {
    $this->postJson('/api/v1/vendor/inventory', ($this->body)(), $this->owner + idem('s2'))->assertCreated();
    $listing = TenantListing::query()->forTenant($this->w['ctx'])->where('store_id', $this->store2->id)->sole();
    World::member($this->w['tenant'], TenantRole::Cashier, 'cashier2@alpha.test', $this->store2);
    $cashier = World::login($this, 'cashier2@alpha.test', 'vendor', 'alpha');

    $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $this->store2->id, 'items' => [['listing_id' => $listing->id, 'quantity' => 3]], 'payment' => ['method' => 'cash', 'tendered_cents' => 200000]], $cashier + idem('sale'))
        ->assertCreated()->assertJsonPath('data.total_cents', 3 * 36000);
    expect(StockSnapshot::query()->forTenant($this->w['ctx'])->where('location_id', $this->loc2->id)->sole()->on_hand)->toBe(1)
        ->and(StockSnapshot::query()->forTenant($this->w['ctx'])->where('location_id', $this->w['location']->id)->sole()->on_hand)->toBe(10);
    $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $this->store2->id, 'items' => [['listing_id' => $listing->id, 'quantity' => 2]], 'payment' => ['method' => 'cash', 'tendered_cents' => 200000]], $cashier + idem('sale-2'))
        ->assertStatus(409)->assertJsonPath('error.code', 'stock_unavailable'); // the other store's stock is not borrowed
});

it('keeps a store-bound manager to their own store when stocking and adjusting', function () {
    World::member($this->w['tenant'], TenantRole::StoreManager, 'manager2@alpha.test', $this->store2);
    $mgr = World::login($this, 'manager2@alpha.test', 'vendor', 'alpha');

    $this->postJson('/api/v1/vendor/inventory', ($this->body)(['store_id' => $this->w['store']->id]), $mgr + idem('other'))->assertForbidden();
    $r = $this->postJson('/api/v1/vendor/inventory', ($this->body)(), $mgr + idem('own'))->assertCreated();
    expect($r->json('data.on_hand'))->toBe(4)->and(collect($r->json('data.locations'))->pluck('location_id')->all())->toBe([$this->loc2->id]); // never sees the other store's stock

    $this->getJson('/api/v1/vendor/inventory/'.$this->s['item']->id, $mgr)->assertOk()->assertJsonPath('data.on_hand', 4);
    $this->getJson('/api/v1/vendor/locations', $mgr)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->loc2->id);
    $url = '/api/v1/vendor/inventory/'.$this->s['item']->id.'/adjustments';
    $this->postJson($url, ['location_id' => $this->loc2->id, 'movement_type' => 'damage', 'quantity_delta' => -1, 'note' => 'Cracked'], $mgr + idem('adj'))->assertCreated();
    $this->postJson($url, ['location_id' => $this->w['location']->id, 'movement_type' => 'damage', 'quantity_delta' => -1, 'note' => 'Cracked'], $mgr + idem('adj-other'))->assertForbidden();
});

it('stocks a second store through AI intake confirmation too', function () {
    World::member($this->w['tenant'], TenantRole::Staff, 'staff@alpha.test');
    $h = World::login($this, 'staff@alpha.test', 'vendor', 'alpha');
    $hp = World::stocked($this->w, $this->refs['HP1630'], 2, 449900, sku: 'ALP-1');
    $a = $this->post('/api/v1/vendor/ai/analyses', ['image' => UploadedFile::fake()->image('p.jpg', 640, 480), 'hint' => 'makita-hp1630'], $h + idem('ai') + ['Accept' => 'application/json'])->assertCreated()->json('data');

    $this->postJson("/api/v1/vendor/ai/analyses/{$a['id']}/confirm", ['decision' => 'confirmed_existing', 'variant_id' => $this->refs['HP1630'],
        'inventory' => ['tenant_sku' => 'ALP-1', 'cost_cents' => 360000, 'price_cents' => 459900, 'quantity' => 5, 'reorder_threshold' => 1, 'store_id' => $this->store2->id]], $h + idem('confirm'))
        ->assertCreated()->assertJsonPath('data.inventory_item_id', $hp['item']->id);
    expect(TenantListing::query()->forTenant($this->w['ctx'])->where('store_id', $this->store2->id)->where('inventory_item_id', $hp['item']->id)->sole()->price_cents)->toBe(459900)
        ->and(StockSnapshot::query()->forTenant($this->w['ctx'])->where('inventory_item_id', $hp['item']->id)->where('location_id', $this->loc2->id)->sole()->on_hand)->toBe(5);
});

it('counts low stock, published listings and open orders for a store-bound user at their store only, like their lists', function () {
    $this->postJson('/api/v1/vendor/inventory', ($this->body)(['quantity' => 1]), $this->owner + idem('s2'))->assertCreated(); // threshold 2: low here, not tenant-wide
    World::member($this->w['tenant'], TenantRole::StoreManager, 'manager2@alpha.test', $this->store2);
    $mgr = World::login($this, 'manager2@alpha.test', 'vendor', 'alpha');

    $this->getJson('/api/v1/vendor/dashboard', $this->owner)->assertOk()->assertJsonPath('data.low_stock_count', 0)->assertJsonPath('data.published_listings', 1);
    $this->getJson('/api/v1/vendor/inventory?low_stock=1', $this->owner)->assertOk()->assertJsonCount(0, 'data');

    $this->getJson('/api/v1/vendor/dashboard', $mgr)->assertOk()->assertJsonPath('data.low_stock_count', 1)->assertJsonPath('data.published_listings', 0)->assertJsonPath('data.open_orders', 0);
    $low = $this->getJson('/api/v1/vendor/inventory?low_stock=1', $mgr)->assertOk()->assertJsonCount(1, 'data');
    expect($low->json('data.0.id'))->toBe($this->s['item']->id)->and($low->json('data.0.low_stock'))->toBeTrue()->and($low->json('data.0.available'))->toBe(1);
});
