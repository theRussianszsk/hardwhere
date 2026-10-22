<?php

use App\Models\AiAnalysis;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TenantListing;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(DemoSeeder::class));

it('seeds a coherent multi-tenant world', function () {
    expect(Tenant::count())->toBe(3)->and(DB::table('stores')->count())->toBe(4)->and(DB::table('users')->where('is_platform_admin', true)->count())->toBe(1)
        ->and(DB::table('tenant_memberships')->pluck('role')->unique()->sort()->values()->all())->toBe(['cashier', 'inventory_manager', 'marketplace_manager', 'owner', 'staff', 'store_manager'])
        ->and(DB::table('products')->count())->toBeGreaterThanOrEqual(30)->and(DB::table('categories')->whereNull('parent_id')->count())->toBe(13);
});

it('has barcoded and unbarcoded hardware, and variants that stay separate', function () {
    expect(DB::table('product_barcodes')->count())->toBeGreaterThan(20)
        ->and(DB::table('product_variants as v')->leftJoin('product_barcodes as b', 'b.variant_id', '=', 'v.id')->whereNull('b.id')->count())->toBeGreaterThan(8);
    $tek = DB::table('product_variants')->whereIn('id', [CatalogSeeder::$refs['TEK100'], CatalogSeeder::$refs['TEK500']])->get();
    expect($tek->pluck('product_id')->unique())->toHaveCount(1)->and($tek->pluck('pack_quantity')->sort()->values()->all())->toBe([100, 500]);
    $bd = DB::table('product_variants')->whereIn('id', [CatalogSeeder::$refs['BCD001-12V'], CatalogSeeder::$refs['BCD001-20V']])->get();
    expect($bd->pluck('variant_key')->unique())->toHaveCount(2);
});

it('stocks the same canonical product in several tenants with different SKUs, prices and quantities', function () {
    foreach (['STH16', 'TEK100', 'GSB13', 'CEMENT'] as $ref) {
        $items = InventoryItem::query()->withoutGlobalScopes()->where('variant_id', CatalogSeeder::$refs[$ref])->get();
        expect($items->pluck('tenant_id')->unique()->count())->toBeGreaterThanOrEqual(2, "$ref should be stocked by 2+ tenants");
        $listings = TenantListing::query()->withoutGlobalScopes()->where('variant_id', CatalogSeeder::$refs[$ref])->get();
        expect($listings->pluck('price_cents')->unique()->count())->toBeGreaterThan(1, "$ref prices differ by tenant")
            ->and($listings->pluck('tenant_sku')->unique()->count())->toBe($listings->count() - ($ref === 'STH16' || $ref === 'TEK100' ? 1 : 0)); // Tenant A reuses its SKU in its second store
    }
    // Tenant A does not yet carry Makita HP1630: that is the live AI-intake demo.
    expect(InventoryItem::query()->withoutGlobalScopes()->where('variant_id', CatalogSeeder::$refs['HP1630'])->where('tenant_id', Tenant::where('slug', 'dela-cruz-hardware')->value('id'))->exists())->toBeFalse();
});

it('keeps the ledger consistent with every stock snapshot, and nothing is oversold', function () {
    $bad = DB::select('SELECT s.id FROM stock_snapshots s WHERE s.on_hand <> COALESCE((SELECT SUM(quantity_delta) FROM stock_ledger_entries l WHERE l.inventory_item_id = s.inventory_item_id AND l.location_id = s.location_id), 0)
        OR s.reserved <> COALESCE((SELECT SUM(reserved_delta) FROM stock_ledger_entries l WHERE l.inventory_item_id = s.inventory_item_id AND l.location_id = s.location_id), 0)');
    expect($bad)->toBe([])->and(DB::table('stock_snapshots')->whereRaw('on_hand < 0 OR reserved < 0 OR reserved > on_hand')->count())->toBe(0);
    expect(DB::table('stock_ledger_entries')->pluck('movement_type')->unique()->sort()->values()->all())
        ->toContain('opening_balance', 'purchase_receipt', 'damage', 'sale', 'return', 'reservation', 'reservation_release', 'order_fulfillment');
});

it('includes sales, orders in every key state, and published/draft listings', function () {
    expect(Sale::query()->withoutGlobalScopes()->pluck('status')->unique()->sort()->values()->all())->toBe(['completed', 'voided']);
    expect(Order::query()->withoutGlobalScopes()->pluck('status')->unique()->sort()->values()->all())->toBe(['cancelled', 'confirmed', 'expired', 'fulfilled']);
    expect(DB::table('reservations')->pluck('status')->unique()->sort()->values()->all())->toBe(['active', 'cancelled', 'converted', 'expired']);
    expect(TenantListing::query()->withoutGlobalScopes()->where('publication_status', 'published')->count())->toBeGreaterThan(30);
});

it('includes a variant example and a low-confidence AI example, both labelled as demo', function () {
    $analyses = AiAnalysis::query()->with('candidates')->get();
    expect($analyses)->toHaveCount(2)->and($analyses->every(fn ($a) => $a->is_mock && $a->provider === 'mock'))->toBeTrue();
    $variant = $analyses->first(fn ($a) => $a->candidates->pluck('classification')->contains('likely_variant') && $a->extracted['model'] === 'HP1630K');
    $low = $analyses->first(fn ($a) => in_array('Low-confidence brand', $a->warnings, true));
    expect($variant)->not->toBeNull()->and($low)->not->toBeNull()->and((float) $low->overall_confidence)->toBeLessThan(0.7);
});

it('keeps tenant-specific aliases and images attached to one tenant', function () {
    expect(DB::table('product_aliases')->whereNotNull('tenant_id')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('product_images')->whereNotNull('tenant_id')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('product_images')->whereNull('tenant_id')->count())->toBeGreaterThan(25);
});
