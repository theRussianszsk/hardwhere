<?php

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

beforeEach(function () {
    $this->refs = World::catalog();
    $this->a = World::tenant('alpha');
    $this->b = World::tenant('bravo');
    $this->ia = World::stocked($this->a, $this->refs['STH16'], 10, 35000);
    $this->ib = World::stocked($this->b, $this->refs['STH16'], 4, 39000);
    $this->ownerA = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $this->ownerB = World::login($this, 'owner@bravo.test', 'vendor', 'bravo');
});

it('never lists or returns another tenant\'s inventory, ledger, or listings', function () {
    $list = $this->getJson('/api/v1/vendor/inventory', $this->ownerB)->assertOk()->json('data');
    expect(collect($list)->pluck('id')->all())->toBe([$this->ib['item']->id]);

    $this->getJson('/api/v1/vendor/inventory/'.$this->ia['item']->id, $this->ownerB)->assertNotFound();
    $this->getJson('/api/v1/vendor/inventory/'.$this->ia['item']->id.'/ledger', $this->ownerB)->assertNotFound();
    expect(collect($this->getJson('/api/v1/vendor/listings', $this->ownerB)->json('data'))->pluck('id')->all())->toBe([$this->ib['listing']->id]);
});

it('rejects cross-tenant writes: adjustments, price changes, and checkout of foreign listings', function () {
    $locA = $this->a['location']->id;
    $this->postJson('/api/v1/vendor/inventory/'.$this->ia['item']->id.'/adjustments', ['location_id' => $locA, 'movement_type' => 'manual_adjustment', 'quantity_delta' => -5, 'note' => 'x'], $this->ownerB + idem())->assertNotFound();
    $this->patchJson('/api/v1/vendor/listings/'.$this->ia['listing']->id, ['expected_row_version' => 1, 'price_cents' => 100], $this->ownerB)->assertNotFound();
    $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $this->b['store']->id, 'items' => [['listing_id' => $this->ia['listing']->id, 'quantity' => 1]],
        'payment' => ['method' => 'cash', 'tendered_cents' => 99999]], $this->ownerB + idem())->assertStatus(422)->assertJsonPath('error.code', 'listing_not_found');

    expect(DB::table('stock_snapshots')->where('inventory_item_id', $this->ia['item']->id)->value('on_hand'))->toBe(10);
});

it('ignores client-supplied tenant identifiers; the token decides', function () {
    $r = $this->postJson('/api/v1/vendor/inventory/'.$this->ia['item']->id.'/adjustments',
        ['tenant_id' => $this->a['tenant']->id, 'location_id' => $this->a['location']->id, 'movement_type' => 'manual_adjustment', 'quantity_delta' => 1, 'note' => 'x'],
        $this->ownerB + idem() + ['X-Tenant-Id' => (string) $this->a['tenant']->id]);
    $r->assertNotFound();
    $this->getJson('/api/v1/vendor/inventory?tenant_id='.$this->a['tenant']->id, $this->ownerB + ['X-Tenant-Id' => (string) $this->a['tenant']->id])->assertOk()->assertJsonCount(1, 'data');
});

it('keeps staff, sales and orders private per tenant', function () {
    World::member($this->a['tenant'], TenantRole::Cashier, 'cashier@alpha.test', $this->a['store']);
    $cashier = World::login($this, 'cashier@alpha.test', 'vendor', 'alpha');
    $sale = $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $this->a['store']->id, 'items' => [['listing_id' => $this->ia['listing']->id, 'quantity' => 1]],
        'payment' => ['method' => 'cash', 'tendered_cents' => 99999]], $cashier + idem())->assertCreated()->json('data.id');

    $this->getJson("/api/v1/vendor/sales/$sale", $this->ownerB)->assertNotFound();
    expect($this->getJson('/api/v1/vendor/sales', $this->ownerB)->json('data'))->toBe([]);
    expect(collect($this->getJson('/api/v1/vendor/staff', $this->ownerB)->json('data'))->pluck('email')->all())->toBe(['owner@bravo.test']);
    $this->postJson("/api/v1/vendor/sales/$sale/void", ['reason' => 'x'], $this->ownerB)->assertNotFound();
});

it('hides cost from roles without inventory.cost.view, even in listings and sync', function () {
    World::member($this->a['tenant'], TenantRole::Cashier, 'cashier@alpha.test', $this->a['store']);
    $cashier = World::login($this, 'cashier@alpha.test', 'vendor', 'alpha');
    config(['hardwhere.sync.xmin_horizon' => false]);

    expect($this->getJson('/api/v1/vendor/inventory', $this->ownerA)->json('data.0'))->toHaveKey('cost_cents');
    expect($this->getJson('/api/v1/vendor/inventory', $cashier)->json('data.0'))->not->toHaveKey('cost_cents')->not->toHaveKey('supplier_id');
    expect(json_encode($this->getJson('/api/v1/vendor/sync/snapshot?entity=inventory_item', $cashier)->json()))->not->toContain('cost_cents');
    $this->getJson('/api/v1/vendor/suppliers', $cashier)->assertForbidden();
});

it('separates vendor tokens from customer tokens', function () {
    World::customer('cust@test.test');
    $cust = World::login($this, 'cust@test.test', 'customer');
    $this->getJson('/api/v1/vendor/inventory', $cust)->assertForbidden();
    $this->getJson('/api/v1/customer/orders', $this->ownerA)->assertForbidden();
    $this->getJson('/api/v1/vendor/inventory')->assertUnauthorized();
});

it('scopes Eloquent queries to the bound tenant as defense in depth', function () {
    TenantContext::bind(World::ctx($this->b['tenant'], $this->b['owner'], TenantRole::Owner));
    expect(InventoryItem::query()->pluck('id')->all())->toBe([$this->ib['item']->id])
        ->and(InventoryItem::query()->find($this->ia['item']->id))->toBeNull()
        ->and(InventoryItem::query()->forTenant($this->a['tenant']->id)->count())->toBe(1); // explicit forTenant is the deliberate, auditable opt-out
    TenantContext::bind(null);

    expect(fn () => InventoryLocation::create(['store_id' => $this->a['store']->id, 'name' => 'X', 'code' => 'X']))->toThrow(LogicException::class);
});

it('makes cross-tenant references impossible at the database level (composite foreign keys)', function () {
    $insert = fn (array $row) => DB::transaction(fn () => DB::table('stock_snapshots')->insert($row));
    // item of tenant A, location of tenant B
    expect(fn () => $insert(['tenant_id' => $this->a['tenant']->id, 'inventory_item_id' => $this->ia['item']->id, 'location_id' => $this->b['location']->id, 'on_hand' => 1, 'reserved' => 0]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('sale_items')->insert(['tenant_id' => $this->b['tenant']->id, 'sale_id' => 999999, 'listing_id' => $this->ia['listing']->id,
        'inventory_item_id' => $this->ib['item']->id, 'variant_id' => $this->refs['STH16'], 'sku' => 'x', 'name' => 'x', 'quantity' => 1, 'unit_price_cents' => 1, 'line_total_cents' => 1])))
        ->toThrow(QueryException::class);
});

it('keeps tenant sync feeds separate', function () {
    config(['hardwhere.sync.xmin_horizon' => false]);
    $feedB = $this->getJson('/api/v1/vendor/sync/changes?cursor=0.0', $this->ownerB)->assertOk()->json('changes');
    $ids = collect($feedB)->where('entity', 'inventory_item')->pluck('id');
    expect($ids->all())->toBe([$this->ib['item']->id]);
    expect(json_encode($feedB))->not->toContain('owner@alpha');
});
