<?php

use App\Models\InventoryItem;
use App\Models\StockLedgerEntry;
use App\Models\StockSnapshot;
use App\Models\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Tests\Support\World;

/**
 * The 18-step end-to-end demonstration, over real HTTP routes, on the seeded demo data. This is the acceptance spine.
 */
it('runs the full vendor -> customer -> POS -> isolation demo scenario', function () {
    config(['hardwhere.sync.xmin_horizon' => false]); // whole test runs in one transaction
    $this->seed(DemoSeeder::class);
    $photo = fn () => UploadedFile::fake()->image('tool.jpg', 800, 600);

    // 1. Vendor staff signs in to Tenant A
    $staff = World::login($this, 'staff.a@hardwhere.test', 'vendor', 'dela-cruz-hardware');
    $storeId = collect($this->getJson('/api/v1/vendor/stores', $staff)->assertOk()->json('data'))->firstWhere('code', 'DCH-MB')['id'];
    $vendorCursor = $this->getJson('/api/v1/vendor/sync/snapshot?entity=listing', $staff)->assertOk()->json('cursor');

    // 2-4. Photograph an unbarcoded power tool; Gemma-abstraction returns attributes + candidate matches (labelled demo AI)
    $analysis = $this->post('/api/v1/vendor/ai/analyses', ['image' => $photo(), 'hint' => 'makita-hp1630'], $staff + idem('scn-a') + ['Accept' => 'application/json'])->assertCreated();
    expect($analysis->json('data.is_mock'))->toBeTrue()
        ->and($analysis->json('data.provider_label'))->toContain('Demo AI')
        ->and($analysis->json('data.extracted.model'))->toBe('HP1630')
        ->and($analysis->json('data.requires_confirmation'))->toBeTrue();
    $top = $analysis->json('data.candidates.0');
    expect($top['classification'])->toBe('exact_match')->and($top['variant']['model_number'])->toBe('HP1630')->and($top['matched_fields'])->toContain('model');
    $variantId = $top['variant']['id'];
    $productId = $top['product']['id'];

    // nothing is stocked until a human confirms
    $tenantIds = Tenant::query()->orderBy('id')->pluck('id', 'slug'); // sequences survive rollbacks, so never hardcode ids
    expect(InventoryItem::query()->withoutGlobalScopes()->where('variant_id', $variantId)->where('tenant_id', $tenantIds['dela-cruz-hardware'])->exists())->toBeFalse();

    // 5-7. Staff confirms the variant and enters SKU, cost, price, quantity, location -> inventory + opening ledger entry
    $confirm = $this->postJson('/api/v1/vendor/ai/analyses/'.$analysis->json('data.id').'/confirm', [
        'decision' => 'confirmed_existing', 'variant_id' => $variantId,
        'inventory' => ['tenant_sku' => 'DCH-HP1630', 'cost_cents' => 360000, 'price_cents' => 449900, 'quantity' => 1, 'reorder_threshold' => 1, 'store_id' => $storeId],
    ], $staff + idem('scn-confirm'))->assertCreated();
    expect($confirm->json('data.ai_suggestion_preserved'))->toBeTrue()->and($confirm->json('data.tenant_input_preserved'))->toBeTrue();
    $itemId = $confirm->json('data.inventory_item_id');
    $ledger = StockLedgerEntry::query()->withoutGlobalScopes()->where('inventory_item_id', $itemId)->get();
    expect($ledger)->toHaveCount(1)->and($ledger[0]->movement_type)->toBe('opening_balance')->and($ledger[0]->quantity_delta)->toBe(1);

    // marketplace manager publishes the listing (staff cannot manage listings)
    $market = World::login($this, 'market.a@hardwhere.test', 'vendor', 'dela-cruz-hardware');
    $listing = collect($this->getJson('/api/v1/vendor/listings?q=DCH-HP1630', $market)->assertOk()->json('data'))->first();
    expect($listing['publication_status'])->toBe('draft');
    $this->patchJson("/api/v1/vendor/listings/{$listing['id']}", ['expected_row_version' => $listing['row_version'], 'publish' => true, 'pickup_available' => true], $staff)->assertForbidden();
    $this->patchJson("/api/v1/vendor/listings/{$listing['id']}", ['expected_row_version' => $listing['row_version'], 'publish' => true, 'pickup_available' => true], $market)->assertOk()->assertJsonPath('data.publication_status', 'published');

    // 8-10. Customer searches the shared catalog; sees Tenant A among stores with price, availability, store info, directions
    $search = $this->getJson('/api/v1/public/search?q=hammer drill')->assertOk();
    expect(collect($search->json('data'))->pluck('id'))->toContain($productId);
    $stores = $this->getJson("/api/v1/public/products/$productId/listings?lat=10.3200&lng=123.9100")->assertOk(); // a buyer in Mabolo, Cebu City
    $mine = collect($stores->json('data'))->first(fn ($l) => $l['sku'] === 'DCH-HP1630');
    expect($mine)->not->toBeNull()
        ->and($mine['price_cents'])->toBe(449900)
        ->and($mine['availability']['status'])->toBe('low_stock')
        ->and($mine['store']['business_name'])->toBe('Dela Cruz Hardware')
        ->and($mine['store']['directions_url'])->toContain('google.com/maps')
        ->and($mine['store']['phone'])->not->toBeEmpty()
        ->and(collect($stores->json('data'))->pluck('store.business_name')->unique()->count())->toBeGreaterThan(1) // same product at other tenants
        ->and(json_encode($stores->json()))->not->toContain('cost_cents');
    expect($stores->json('meta.server_time'))->not->toBeEmpty();
    $names = collect($stores->json('data'))->pluck('store.business_name');
    expect($names->first())->toBe('Dela Cruz Hardware')->and($names->last())->toBe('Kamuning Hardware')->and($names)->not->toContain('Old Pier Hardware');

    // customer photo search also resolves to the shared canonical product
    $customer = World::login($this, 'ana.customer@hardwhere.test', 'customer');
    $photoSearch = $this->post('/api/v1/customer/search/photo', ['image' => $photo(), 'hint' => 'makita-hp1630'], $customer + idem('scn-cphoto') + ['Accept' => 'application/json'])->assertOk();
    expect($photoSearch->json('data.candidates.0.product.id'))->toBe($productId);

    // 11-12. Customer reserves the only unit: stock is atomically reserved
    $order = $this->postJson('/api/v1/customer/orders', [
        'items' => [['listing_id' => $mine['id'], 'quantity' => 1, 'expected_unit_price_cents' => 449900]],
        'fulfillment_type' => 'pickup', 'contact_name' => 'Ana Reyes', 'contact_phone' => '0917 123 0001',
    ], $customer + idem('scn-order'))->assertCreated();
    expect($order->json('data.status'))->toBe('reserved')->and($order->json('data.reservation.status'))->toBe('active');
    $snap = StockSnapshot::query()->withoutGlobalScopes()->where('inventory_item_id', $itemId)->first();
    expect([$snap->on_hand, $snap->reserved])->toBe([1, 1]);

    // 13-14. Cashier tries to sell the same unit (even though a stale cache would show 1): server refuses, no oversell
    $cashier = World::login($this, 'cashier.a@hardwhere.test', 'vendor', 'dela-cruz-hardware');
    $cart = ['store_id' => $storeId, 'items' => [['listing_id' => $listing['id'], 'quantity' => 1, 'expected_unit_price_cents' => 449900]], 'payment' => ['method' => 'cash', 'tendered_cents' => 500000]];
    $this->postJson('/api/v1/vendor/pos/checkout', $cart, $cashier + idem('scn-sale-1'))->assertStatus(409)->assertJsonPath('error.code', 'stock_unavailable')->assertJsonPath('error.details.available', 0);
    expect(StockSnapshot::query()->withoutGlobalScopes()->where('inventory_item_id', $itemId)->value('on_hand'))->toBe(1);

    // 15. Inventory manager receives 2 more units; the cashier's checkout now succeeds and writes sale + items + ledger + snapshot
    $inv = World::login($this, 'inventory.a@hardwhere.test', 'vendor', 'dela-cruz-hardware');
    $locationId = collect($this->getJson('/api/v1/vendor/locations', $inv)->json('data'))->firstWhere('store_id', $storeId)['id'];
    $this->postJson("/api/v1/vendor/inventory/$itemId/adjustments", ['location_id' => $locationId, 'movement_type' => 'purchase_receipt', 'quantity_delta' => 2, 'note' => 'Restock'], $inv + idem('scn-restock'))->assertCreated();
    $sale = $this->postJson('/api/v1/vendor/pos/checkout', $cart, $cashier + idem('scn-sale-2'))->assertCreated();
    expect($sale->json('data.total_cents'))->toBe(449900)->and($sale->json('data.change_cents'))->toBe(50100)->and($sale->json('data.items'))->toHaveCount(1)
        ->and($sale->json('data.receipt_no'))->toStartWith('DCH-MB-');
    $snap->refresh();
    expect([$snap->on_hand, $snap->reserved])->toBe([2, 1]); // 1 + 2 received - 1 sold; reservation untouched
    expect(StockLedgerEntry::query()->withoutGlobalScopes()->where('inventory_item_id', $itemId)->where('movement_type', 'sale')->count())->toBe(1);
    // retry of the same checkout (lost response) has no second effect
    $this->postJson('/api/v1/vendor/pos/checkout', $cart, $cashier + idem('scn-sale-2'))->assertOk()->assertJsonPath('data.id', $sale->json('data.id'))->assertJsonPath('replayed', true);
    expect(StockSnapshot::query()->withoutGlobalScopes()->where('inventory_item_id', $itemId)->value('on_hand'))->toBe(2);

    // caches refresh through sync: the vendor delta carries the item, the public delta carries the listing's new availability
    $delta = $this->getJson('/api/v1/vendor/sync/changes?cursor='.$vendorCursor, $inv)->assertOk();
    expect(collect($delta->json('changes'))->where('entity', 'inventory_item')->pluck('id'))->toContain($itemId);

    // 16. Order moves through the vendor workflow and the customer sees it; fulfilment consumes the reserved unit
    $orderId = $order->json('data.id');
    foreach (['confirmed', 'preparing', 'ready_for_pickup', 'fulfilled'] as $to) {
        $this->postJson("/api/v1/vendor/orders/$orderId/transition", ['to' => $to], $market)->assertOk()->assertJsonPath('data.status', $to);
    }
    $this->getJson("/api/v1/customer/orders/$orderId", $customer)->assertOk()->assertJsonPath('data.status', 'fulfilled');
    $snap->refresh();
    expect([$snap->on_hand, $snap->reserved])->toBe([1, 0]);

    // 17. Tenant B cannot see Tenant A's private inventory, costs, staff, sales or orders
    $b = World::login($this, 'owner.b@hardwhere.test', 'vendor', 'mabini-builders');
    $this->getJson("/api/v1/vendor/inventory/$itemId", $b)->assertNotFound();
    $this->getJson("/api/v1/vendor/orders/$orderId", $b)->assertNotFound();
    $this->getJson('/api/v1/vendor/sales/'.$sale->json('data.id'), $b)->assertNotFound();
    expect(json_encode($this->getJson('/api/v1/vendor/inventory', $b)->json()))->not->toContain('DCH-')
        ->and(json_encode($this->getJson('/api/v1/vendor/staff', $b)->json()))->not->toContain('.a@hardwhere.test')->toContain('owner.b@hardwhere.test')
        ->and(json_encode($this->getJson('/api/v1/vendor/sales', $b)->json()))->not->toContain('DCH-MB');

    // 18. The canonical product is still shared: Tenant B stocks the same product under its own SKU and price
    $bItems = collect($this->getJson('/api/v1/vendor/inventory?q=HP1630', $b)->json('data'));
    expect($bItems->pluck('sku'))->toContain('MBS-0001')
        ->and(InventoryItem::query()->withoutGlobalScopes()->where('variant_id', $variantId)->pluck('tenant_id')->sort()->values()->all())->toBe($tenantIds->only(['dela-cruz-hardware', 'mabini-builders', 'visayas-tools', 'kamuning-hardware'])->sort()->values()->all()); // A (new), B, C and the Manila store
});
