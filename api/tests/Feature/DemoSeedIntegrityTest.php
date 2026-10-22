<?php

use App\Models\AiAnalysis;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\TenantListing;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo data set is part of the product: it must obey the same stock, tenancy and catalog rules as live data.
 * One test with sections, because seeding the full 30-day history is the slow part.
 */
it('produces a demo data set that obeys the stock, tenancy, catalog and AI rules', function () {
    $this->seed(DemoSeeder::class);
    $this->tenants = Tenant::query()->pluck('id', 'slug');

    // 1. Every stock snapshot reconciles with its append-only ledger
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

    // 2. The same canonical product is stocked by several tenants with their own SKU, price and cost
    $listings = TenantListing::query()->withoutGlobalScopes()->with('inventoryItem')->where('variant_id', CatalogSeeder::$refs['BRK20'])->get();
    expect($listings->pluck('tenant_id')->unique()->count())->toBeGreaterThanOrEqual(4)
        ->and($listings->pluck('tenant_sku')->unique()->count())->toBeGreaterThanOrEqual(4)
        ->and($listings->pluck('price_cents')->unique()->count())->toBeGreaterThan(1);

    // tenant A has not stocked the live-demo intakes yet
    $a = $this->tenants['dela-cruz-hardware'];
    expect(DB::table('inventory_items')->where('tenant_id', $a)->where('variant_id', CatalogSeeder::$refs['HP1630'])->exists())->toBeFalse();

    // 3. Customers only see published listings of active tenants with a marketplace plan, without private fields
    $tl = $this->getJson('/api/v1/public/search?q=circuit breaker')->assertOk();
    $product = collect($tl->json('data'))->first();
    $stores = $this->getJson("/api/v1/public/products/{$product['id']}/listings?lat=10.32&lng=123.91")->assertOk();

    $names = collect($stores->json('data'))->pluck('store.business_name');
    expect($names)->toContain('Dela Cruz Hardware')
        ->not->toContain('Old Pier Hardware') // suspended
        ->not->toContain('Mactan Electrical & Plumbing Center'); // Starter plan: POS only
    expect(json_encode($stores->json()))->not->toContain('cost_cents')->not->toContain('supplier');

    // 4. Commercially different variants stay apart; unbarcoded stock exists
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

    // 5. AI results are labelled mock results with the human decision recorded
    expect(AiAnalysis::query()->where('is_mock', false)->exists())->toBeFalse();
    expect(DB::table('ai_decisions')->pluck('decision')->sort()->values()->all())->toBe(['created_candidate', 'rejected', 'selected_variant']);
    expect(AiAnalysis::query()->where('classification', 'insufficient_confidence')->exists())->toBeTrue()
        ->and(AiAnalysis::query()->where('classification', 'likely_variant')->exists())->toBeTrue();

    // the tenant-submitted candidate stays private to its tenant until approved
    $candidate = Product::query()->where('status', 'candidate')->sole();
    expect($candidate->submitted_by_tenant_id)->toBe($this->tenants['visayas-tools']);
    expect(collect($this->getJson('/api/v1/public/search?q=expanding foam')->json('data'))->pluck('id'))->not->toContain($candidate->id);
});
