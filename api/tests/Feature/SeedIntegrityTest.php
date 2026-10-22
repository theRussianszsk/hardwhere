<?php

use App\Models\AiAnalysis;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TenantListing;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo data set is part of the product: it must obey the same stock, tenancy, catalog and AI rules as live data.
 * One test with numbered sections, because seeding the full 30-day history is the slow part of the suite.
 */
it('seeds a demo world that obeys the stock, tenancy, catalog and AI rules', function () {
    $this->seed(DemoSeeder::class);
    $this->tenants = Tenant::query()->pluck('id', 'slug');

    // 1. Seeds a coherent multi-tenant world
    expect(Tenant::count())->toBe(7)->and(DB::table('stores')->count())->toBe(8)->and(DB::table('users')->where('is_platform_admin', true)->count())->toBe(1)
        ->and(DB::table('tenant_memberships')->pluck('role')->unique()->sort()->values()->all())->toBe(['cashier', 'inventory_manager', 'marketplace_manager', 'owner', 'staff', 'store_manager'])
        ->and(DB::table('products')->count())->toBeGreaterThanOrEqual(30)->and(DB::table('categories')->whereNull('parent_id')->count())->toBe(13);

    // 2. Has barcoded and unbarcoded hardware, and variants that stay separate
    expect(DB::table('product_barcodes')->count())->toBeGreaterThan(20)
        ->and(DB::table('product_variants as v')->leftJoin('product_barcodes as b', 'b.variant_id', '=', 'v.id')->whereNull('b.id')->count())->toBeGreaterThan(8);
    $tek = DB::table('product_variants')->whereIn('id', [CatalogSeeder::$refs['TEK100'], CatalogSeeder::$refs['TEK500']])->get();
    expect($tek->pluck('product_id')->unique())->toHaveCount(1)->and($tek->pluck('pack_quantity')->sort()->values()->all())->toBe([100, 500]);
    $bd = DB::table('product_variants')->whereIn('id', [CatalogSeeder::$refs['BCD001-12V'], CatalogSeeder::$refs['BCD001-20V']])->get();
    expect($bd->pluck('variant_key')->unique())->toHaveCount(2);

    // 3. Stocks the same canonical product in several tenants with different SKUs, prices and quantities
    foreach (['STH16', 'TEK100', 'GSB13', 'CEMENT'] as $ref) {
        $items = InventoryItem::query()->withoutGlobalScopes()->where('variant_id', CatalogSeeder::$refs[$ref])->get();
        expect($items->pluck('tenant_id')->unique()->count())->toBeGreaterThanOrEqual(2, "$ref should be stocked by 2+ tenants");
        $listings = TenantListing::query()->withoutGlobalScopes()->where('variant_id', CatalogSeeder::$refs[$ref])->get();
        expect($listings->pluck('price_cents')->unique()->count())->toBeGreaterThan(1, "$ref prices differ by tenant")
            ->and($listings->groupBy('tenant_id')->every(fn ($g) => $g->pluck('tenant_sku')->unique()->count() === 1))->toBeTrue() // a tenant keeps one SKU across its stores
            ->and($listings->pluck('tenant_sku')->unique()->count())->toBe($listings->pluck('tenant_id')->unique()->count());
    }
    // Tenant A does not yet carry Makita HP1630: that is the live AI-intake demo.
    expect(InventoryItem::query()->withoutGlobalScopes()->where('variant_id', CatalogSeeder::$refs['HP1630'])->where('tenant_id', Tenant::where('slug', 'dela-cruz-hardware')->value('id'))->exists())->toBeFalse();

    // 4. Keeps the ledger consistent with every stock snapshot, and nothing is oversold
    $bad = DB::select('SELECT s.id FROM stock_snapshots s WHERE s.on_hand <> COALESCE((SELECT SUM(quantity_delta) FROM stock_ledger_entries l WHERE l.inventory_item_id = s.inventory_item_id AND l.location_id = s.location_id), 0)
        OR s.reserved <> COALESCE((SELECT SUM(reserved_delta) FROM stock_ledger_entries l WHERE l.inventory_item_id = s.inventory_item_id AND l.location_id = s.location_id), 0)');
    expect($bad)->toBe([])->and(DB::table('stock_snapshots')->whereRaw('on_hand < 0 OR reserved < 0 OR reserved > on_hand')->count())->toBe(0);
    expect(DB::table('stock_ledger_entries')->pluck('movement_type')->unique()->sort()->values()->all())
        ->toContain('opening_balance', 'purchase_receipt', 'damage', 'sale', 'return', 'reservation', 'reservation_release', 'order_fulfillment');

    // 5. Includes sales, orders in every key state, and published/draft listings
    expect(Sale::query()->withoutGlobalScopes()->pluck('status')->unique()->sort()->values()->all())->toBe(['completed', 'voided']);
    expect(Order::query()->withoutGlobalScopes()->pluck('status')->unique()->sort()->values()->all())->toBe(['cancelled', 'confirmed', 'expired', 'fulfilled', 'preparing', 'ready_for_pickup', 'reserved']);
    expect(DB::table('reservations')->pluck('status')->unique()->sort()->values()->all())->toBe(['active', 'cancelled', 'converted', 'expired']);
    expect(TenantListing::query()->withoutGlobalScopes()->where('publication_status', 'published')->count())->toBeGreaterThan(30);

    // 6. Includes a variant example and a low-confidence AI example, both labelled as demo
    $analyses = AiAnalysis::query()->with('candidates')->get();
    expect($analyses->count())->toBeGreaterThanOrEqual(2)->and($analyses->every(fn ($a) => $a->is_mock && $a->provider === 'mock'))->toBeTrue();
    $variant = $analyses->first(fn ($a) => $a->candidates->pluck('classification')->contains('likely_variant') && $a->extracted['model'] === 'HP1630K');
    $low = $analyses->first(fn ($a) => in_array('Low-confidence brand', $a->warnings, true));
    expect($variant)->not->toBeNull()->and($low)->not->toBeNull()->and((float) $low->overall_confidence)->toBeLessThan(0.7);

    // 7. Keeps tenant-specific aliases and images attached to one tenant
    expect(DB::table('product_aliases')->whereNotNull('tenant_id')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('product_images')->whereNotNull('tenant_id')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('product_images')->whereNull('tenant_id')->count())->toBeGreaterThan(25);

    // 8. Every stock snapshot reconciles with its append-only ledger
    $mismatches = DB::table('stock_snapshots as s')
        ->leftJoinSub(DB::table('stock_ledger_entries')->selectRaw('inventory_item_id, location_id, SUM(quantity_delta) q, SUM(reserved_delta) r')->groupBy('inventory_item_id', 'location_id'), 'l',
            fn ($j) => $j->on('l.inventory_item_id', '=', 's.inventory_item_id')->on('l.location_id', '=', 's.location_id'))
        ->whereRaw('COALESCE(l.q, 0) <> s.on_hand OR COALESCE(l.r, 0) <> s.reserved')->count();
    expect($mismatches)->toBe(0);

    // reserved units equal the open reservation lines, nothing more
    $held = DB::table('reservation_lines as rl')->join('reservations as r', 'r.id', '=', 'rl.reservation_id')->where('r.status', 'active')->sum('rl.quantity');
    expect((int) DB::table('stock_snapshots')->sum('reserved'))->toBe((int) $held);

    // history spans the last 30 days, in Philippine business hours
    $hours = DB::table('sales')->selectRaw("DISTINCT EXTRACT(HOUR FROM completed_at AT TIME ZONE 'Asia/Manila')::int h")->pluck('h');
    expect($hours->min())->toBeGreaterThanOrEqual(8)->and($hours->max())->toBeLessThanOrEqual(17)
        ->and(DB::table('sales')->min('completed_at'))->toBeLessThan(now()->subDays(25)->toDateTimeString());

    expect(DB::table('stock_ledger_entries')->distinct()->pluck('movement_type')->all())->toContain(
        'opening_balance', 'purchase_receipt', 'sale', 'return', 'reservation', 'reservation_release', 'order_fulfillment', 'transfer_in', 'transfer_out', 'damage', 'loss', 'correction');
    expect(DB::table('orders')->distinct()->pluck('status')->all())->toContain('reserved', 'confirmed', 'preparing', 'ready_for_pickup', 'fulfilled', 'cancelled', 'expired');

    // 9. The same canonical product is stocked by several tenants with their own SKU, price and cost
    $listings = TenantListing::query()->withoutGlobalScopes()->with('inventoryItem')->where('variant_id', CatalogSeeder::$refs['BRK20'])->get();
    expect($listings->pluck('tenant_id')->unique()->count())->toBeGreaterThanOrEqual(4)
        ->and($listings->pluck('tenant_sku')->unique()->count())->toBeGreaterThanOrEqual(4)
        ->and($listings->pluck('price_cents')->unique()->count())->toBeGreaterThan(1);

    // tenant A has not stocked the live-demo intakes yet
    $a = $this->tenants['dela-cruz-hardware'];
    expect(DB::table('inventory_items')->where('tenant_id', $a)->where('variant_id', CatalogSeeder::$refs['HP1630'])->exists())->toBeFalse();

    // 10. Customers only see published listings of active tenants with a marketplace plan, without private fields
    $tl = $this->getJson('/api/v1/public/search?q=circuit breaker')->assertOk();
    $product = collect($tl->json('data'))->first();
    $stores = $this->getJson("/api/v1/public/products/{$product['id']}/listings?lat=10.32&lng=123.91")->assertOk();

    $names = collect($stores->json('data'))->pluck('store.business_name');
    expect($names)->toContain('Dela Cruz Hardware')
        ->not->toContain('Old Pier Hardware') // suspended
        ->not->toContain('Mactan Electrical & Plumbing Center'); // Starter plan: POS only
    expect(json_encode($stores->json()))->not->toContain('cost_cents')->not->toContain('supplier');

    // 11. Commercially different variants stay apart; unbarcoded stock exists
    // 720W vs 840W grinder, 20A vs 30A breaker, 100- vs 500-pack screws: separate variants, never merged
    foreach ([['GA4030', 'MK9556HN'], ['BRK20', 'BRK30'], ['TEK100', 'TEK500'], ['NAIL3', 'NAIL3-BOX'], ['REBAR10', 'REBAR10-G33']] as [$x, $y]) {
        $vx = ProductVariant::findOrFail(CatalogSeeder::$refs[$x]);
        $vy = ProductVariant::findOrFail(CatalogSeeder::$refs[$y]);
        expect($vx->id)->not->toBe($vy->id)->and($vx->variant_key)->not->toBe($vy->variant_key);
    }

    $tops = DB::table('categories')->whereNull('parent_id')->pluck('id');
    foreach ($tops as $top) {
        $count = Product::query()->whereHas('category', fn ($q) => $q->where('id', $top)->orWhere('parent_id', $top))->count();
        expect($count)->toBeGreaterThan(0);
    }
    expect(ProductVariant::query()->whereDoesntHave('barcodes')->count())->toBeGreaterThan(50);
    expect(DB::table('product_attributes')->where('key', 'weight_oz')->where('value_number', '>', 100)->exists())->toBeFalse(); // bags are in kg

    // 12. AI results are labelled mock results with the human decision recorded
    expect(AiAnalysis::query()->where('is_mock', false)->exists())->toBeFalse();
    expect(DB::table('ai_decisions')->pluck('decision')->sort()->values()->all())->toBe(['created_candidate', 'rejected', 'selected_variant']);
    expect(AiAnalysis::query()->where('classification', 'insufficient_confidence')->exists())->toBeTrue()
        ->and(DB::table('ai_match_candidates')->where('classification', 'likely_variant')->exists())->toBeTrue(); // e.g. HP1630 offered as a variant of the photographed HP1630K

    // the tenant-submitted candidate stays private to its tenant until approved
    $candidate = Product::query()->where('status', 'candidate')->sole();
    expect($candidate->submitted_by_tenant_id)->toBe($this->tenants['visayas-tools']);
    expect(collect($this->getJson('/api/v1/public/search?q=expanding foam')->json('data'))->pluck('id'))->not->toContain($candidate->id);
});
