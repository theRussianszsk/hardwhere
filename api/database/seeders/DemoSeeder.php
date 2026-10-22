<?php

namespace Database\Seeders;

use App\Ai\MockVisionProvider;
use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\AiAnalysis;
use App\Models\InventoryLocation;
use App\Models\PosRegister;
use App\Models\ProductAlias;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TenantListing;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CatalogMatcher;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PosService;
use App\Services\ReservationService;
use App\Services\StockService;
use App\Services\SyncRecorder;
use App\Support\Text;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic end-to-end demo: 3 tenants, 4 stores, every role, the SAME canonical products stocked at different prices/quantities,
 * ledger history, sales, orders in several states, and two AI examples (a variant/duplicate case and a low-confidence case).
 * All demo accounts use the password "password". Never run this against production.
 */
class DemoSeeder extends Seeder
{
    public function run(InventoryService $inventory, PosService $pos, OrderService $orders, ReservationService $reservations, CatalogMatcher $matcher): void
    {
        $this->call(CatalogSeeder::class);
        $ref = CatalogSeeder::$refs;

        User::query()->forceCreate(['name' => 'Platform Admin', 'email' => 'admin@hardwhere.test', 'password' => bcrypt('password'), 'is_platform_admin' => true, 'email_verified_at' => now()]);
        $ana = $this->user('Ana Reyes', 'ana.customer@hardwhere.test', '+639171230001');
        $ben = $this->user('Ben Santos', 'ben.customer@hardwhere.test', '+639171230002');

        // ---- Tenant A: Dela Cruz Hardware (does NOT stock Makita HP1630 yet: that is the AI-intake demo) ----
        $a = Tenant::create(['name' => 'Dela Cruz Hardware', 'slug' => 'dela-cruz-hardware']);
        $qc = $this->store($a, 'Kamuning Branch', 'DCH-QC', '45 K-1st St, Kamuning', 'Quezon City', 'Metro Manila', 14.6285, 121.0366, '+6328123001', true);
        $an = $this->store($a, 'Antipolo Branch', 'DCH-AN', '12 Sumulong Hwy, San Roque', 'Antipolo', 'Rizal', 14.5860, 121.1760, '+6328123002', false);
        $ownerA = $this->member($a, 'Lito Dela Cruz', 'owner.a@hardwhere.test', TenantRole::Owner);
        $this->member($a, 'Maricel Manager', 'manager.a@hardwhere.test', TenantRole::StoreManager, $qc);
        $this->member($a, 'Ino Inventory', 'inventory.a@hardwhere.test', TenantRole::InventoryManager);
        $cashierA = $this->member($a, 'Carlo Cashier', 'cashier.a@hardwhere.test', TenantRole::Cashier, $qc);
        $this->member($a, 'Mia Marketplace', 'market.a@hardwhere.test', TenantRole::MarketplaceManager);
        $this->member($a, 'Sam Staff', 'staff.a@hardwhere.test', TenantRole::Staff);
        $regA = PosRegister::create(['tenant_id' => $a->id, 'store_id' => $qc->id, 'name' => 'Counter 1']);
        PosRegister::create(['tenant_id' => $a->id, 'store_id' => $an->id, 'name' => 'Counter 1']);
        $supA = Supplier::create(['tenant_id' => $a->id, 'name' => 'Metro Tools Wholesale', 'contact_name' => 'Rey', 'phone' => '+6328999001', 'lead_time_days' => 3]);

        // ---- Tenant B: Mabini Builders Supply ----
        $b = Tenant::create(['name' => 'Mabini Builders Supply', 'slug' => 'mabini-builders']);
        $mr = $this->store($b, 'Marikina Showroom', 'MBS-MR', '88 Shoe Ave, Sto. Niño', 'Marikina', 'Metro Manila', 14.6507, 121.1029, '+6329421001', true);
        $ownerB = $this->member($b, 'Berto Mabini', 'owner.b@hardwhere.test', TenantRole::Owner);
        $this->member($b, 'Cora Cashier', 'cashier.b@hardwhere.test', TenantRole::Cashier, $mr);
        PosRegister::create(['tenant_id' => $b->id, 'store_id' => $mr->id, 'name' => 'Counter 1']);

        // ---- Tenant C: Visayas Tools Depot ----
        $c = Tenant::create(['name' => 'Visayas Tools Depot', 'slug' => 'visayas-tools']);
        $cb = $this->store($c, 'Cebu Main', 'VTD-CB', 'Colon St, Cebu City', 'Cebu City', 'Cebu', 10.3157, 123.8854, '+63322551001', true);
        $ownerC = $this->member($c, 'Vicky Villamor', 'owner.c@hardwhere.test', TenantRole::Owner);

        $ctxA = new TenantContext($a->id, $ownerA->id, TenantRole::Owner);
        $ctxB = new TenantContext($b->id, $ownerB->id, TenantRole::Owner);
        $ctxC = new TenantContext($c->id, $ownerC->id, TenantRole::Owner);

        // [ref, sku, cost, price, qty, reorder, delivery]; prices in pesos
        $stockA = [
            ['GSB13', 'DCH-DRL-001', 3100, 3950, 8, 3, true], ['GWS060', 'DCH-GRN-004', 1850, 2350, 6, 2, true], ['DCD771-KIT', 'DCH-DRL-020', 6900, 8495, 4, 2, true],
            ['BCD001-12V', 'DCH-DRL-031', 2500, 3290, 5, 2, true], ['BCD001-20V', 'DCH-DRL-032', 3300, 4190, 0, 2, true], ['STH16', 'DCH-HMR-016', 220, 349, 30, 10, true],
            ['TLPL8', 'DCH-PLR-008', 190, 295, 15, 5, true], ['TEK100', 'DCH-FST-100', 140, 220, 60, 20, true], ['TEK500', 'DCH-FST-500', 600, 890, 12, 5, true],
            ['NAIL3', 'DCH-NL-3', 62, 85, 120, 40, true], ['BOLTM10', 'DCH-BLT-M10', 380, 540, 3, 10, true], ['PVC12', 'DCH-PVC-12', 85, 130, 80, 20, true],
            ['BRK20', 'DCH-BRK-20', 210, 320, 25, 8, true], ['BRK30', 'DCH-BRK-30', 240, 365, 18, 8, true], ['LED9WW', 'DCH-LED-9W', 55, 95, 100, 30, true],
            ['BOY4L', 'DCH-PNT-4L', 780, 1095, 14, 6, true], ['HHAT-Y', 'DCH-HAT-Y', 120, 195, 22, 8, true], ['TAPE5', 'DCH-TAP-5', 140, 235, 20, 6, true],
            ['CEMENT', 'DCH-CEM-40', 235, 265, 150, 50, false], ['BITSET5', 'DCH-BIT-5', 380, 590, 9, 4, true],
        ];
        $stockB = [
            ['HP1630', 'MBS-0001', 3600, 4650, 5, 2, false], ['HP1630K', 'MBS-0002', 4300, 5490, 2, 1, false], ['GSB13', 'MBS-0010', 3200, 3790, 3, 2, false],
            ['STH16', 'MBS-0050', 240, 329, 18, 6, false], ['TEK100', 'MBS-0070', 150, 235, 40, 15, false], ['CEMENT', 'MBS-0100', 240, 275, 300, 80, false],
            ['REBAR10', 'MBS-0110', 205, 265, 250, 80, false], ['PLY12', 'MBS-0120', 680, 895, 40, 10, false], ['BOY16L', 'MBS-0130', 2750, 3650, 6, 3, false],
            ['TILEADH', 'MBS-0140', 330, 465, 35, 10, false], ['BRK30', 'MBS-0150', 250, 380, 10, 5, false],
        ];
        $stockC = [
            ['HP1630', 'VTD-HP1630', 3450, 4399, 1, 1, true], ['DCD771-BARE', 'VTD-DCD-N', 4100, 5290, 6, 2, true], ['BCD001-20V', 'VTD-BCD-20', 3150, 3990, 7, 2, true],
            ['MK4329', 'VTD-JIG-01', 2900, 3750, 4, 1, true], ['STH20', 'VTD-HMR-20', 260, 385, 12, 4, true], ['HHAT-W', 'VTD-HAT-W', 110, 180, 30, 10, true],
        ];

        $itemsA = $this->stock($inventory, $ctxA, $qc, $ref, $stockA, $supA->id);
        $this->stock($inventory, $ctxB, $mr, $ref, $stockB);
        $this->stock($inventory, $ctxC, $cb, $ref, $stockC);

        // Antipolo carries a few items so A has differing stock across stores.
        foreach ([['TEK100', 'DCH-FST-100', 140, 225, 25, 10], ['STH16', 'DCH-HMR-016', 220, 355, 6, 4]] as [$r, $sku, $cost, $price, $qty, $reorder]) {
            $loc = InventoryLocation::query()->where('store_id', $an->id)->first();
            $item = $itemsA[$r];
            $listing = TenantListing::create(['tenant_id' => $a->id, 'store_id' => $an->id, 'inventory_item_id' => $item->id, 'product_id' => $item->variant->product_id, 'variant_id' => $item->variant_id,
                'tenant_sku' => $sku, 'price_cents' => $price * 100, 'uom' => $item->variant->uom, 'visibility' => 'public', 'publication_status' => 'published', 'pickup_available' => true]);
            DB::transaction(fn () => app(StockService::class)->adjust($a->id, $ownerA->id, $item->id, $loc->id, 'opening_balance', $qty, 'Opening balance (Antipolo)'));
            app(SyncRecorder::class)->listing($a->id, $listing->id);
        }

        // Tenant-specific alias and image (isolated to tenant A)
        ProductAlias::create(['variant_id' => $ref['GSB13'], 'product_id' => ProductVariant::find($ref['GSB13'])->product_id, 'tenant_id' => $a->id, 'type' => 'name', 'alias' => 'Bosch pang-konkreto', 'normalized' => Text::normalize('Bosch pang-konkreto')]);
        ProductImage::create(['product_id' => ProductVariant::find($ref['STH16'])->product_id, 'tenant_id' => $a->id, 'path' => '/demo-images/hand-tools.svg', 'alt' => 'Shelf photo (tenant A)', 'source' => 'tenant_upload']);

        // History: purchase receipt, a loss, sales (one voided)
        $inventory->receivePurchase($ctxA, ['location_id' => InventoryLocation::query()->where('store_id', $qc->id)->value('id'), 'supplier_id' => $supA->id, 'reference' => 'PO-2026-0001',
            'items' => [['inventory_item_id' => $itemsA['STH16']->id, 'quantity' => 12, 'unit_cost_cents' => 21500]]]);
        $inventory->adjust($ctxA, $itemsA['PVC12']->id, InventoryLocation::query()->where('store_id', $qc->id)->value('id'), 'damage', -3, 'Cracked in storage');

        $cashierCtx = new TenantContext($a->id, $cashierA->id, TenantRole::Cashier, $qc->id);
        $listingA = fn (string $r) => TenantListing::query()->where('store_id', $qc->id)->where('inventory_item_id', $itemsA[$r]->id)->first();
        [$s1] = $pos->checkout($cashierCtx, ['store_id' => $qc->id, 'items' => [['listing_id' => $listingA('STH16')->id, 'quantity' => 2], ['listing_id' => $listingA('NAIL3')->id, 'quantity' => 5]], 'payment' => ['method' => 'cash', 'tendered_cents' => 200000]], 'seed-sale-0001');
        $pos->checkout($cashierCtx, ['store_id' => $qc->id, 'items' => [['listing_id' => $listingA('LED9WW')->id, 'quantity' => 4]], 'payment' => ['method' => 'gcash', 'reference' => 'GC1234567890'], 'customer' => ['name' => 'Walk-in']], 'seed-sale-0002');
        [$s3] = $pos->checkout($cashierCtx, ['store_id' => $qc->id, 'items' => [['listing_id' => $listingA('TLPL8')->id, 'quantity' => 1]], 'payment' => ['method' => 'cash', 'tendered_cents' => 50000]], 'seed-sale-0003');
        $pos->void($ctxA, $s3->id, 'Customer changed mind');

        // Orders in several states
        $firstTek = TenantListing::query()->where('store_id', $qc->id)->where('inventory_item_id', $itemsA['TEK100']->id)->first();
        [$o1] = $orders->place($ana, ['items' => [['listing_id' => $firstTek->id, 'quantity' => 3]], 'fulfillment_type' => 'pickup', 'contact_name' => 'Ana Reyes', 'contact_phone' => '+639171230001'], 'seed-order-0001');
        $orders->vendorTransition($o1->id, OrderStatus::Confirmed, null, $ownerA->id);

        $bListing = TenantListing::query()->where('tenant_id', $b->id)->first();
        [$o2] = $orders->place($ben, ['items' => [['listing_id' => $bListing->id, 'quantity' => 1]], 'fulfillment_type' => 'pickup', 'contact_name' => 'Ben Santos', 'contact_phone' => '+639171230002'], 'seed-order-0002');
        foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup, OrderStatus::Fulfilled] as $to) {
            $orders->vendorTransition($o2->id, $to, null, $ownerB->id);
        }

        [$o3] = $orders->place($ana, ['items' => [['listing_id' => $listingA('HHAT-Y')?->id ?? $listingA('TAPE5')->id, 'quantity' => 1]], 'fulfillment_type' => 'pickup', 'contact_name' => 'Ana Reyes', 'contact_phone' => '+639171230001'], 'seed-order-0003');
        $orders->customerCancel($ana, $o3->id, 'Found it elsewhere');

        [$o4] = $orders->place($ben, ['items' => [['listing_id' => $listingA('TAPE5')->id, 'quantity' => 1]], 'fulfillment_type' => 'pickup', 'contact_name' => 'Ben Santos', 'contact_phone' => '+639171230002'], 'seed-order-0004');
        Reservation::query()->where('order_id', $o4->id)->update(['expires_at' => now()->subMinutes(5)]);
        $reservations->expireDue();

        $this->aiExamples($matcher, $a, User::query()->where('email', 'staff.a@hardwhere.test')->first());
    }

    /** @param  array<string, int>  $ref  @param  list<array<int, mixed>>  $rows  @return array<string, \App\Models\InventoryItem> */
    private function stock(InventoryService $inventory, TenantContext $ctx, Store $store, array $ref, array $rows, ?int $supplierId = null): array
    {
        $items = [];
        foreach ($rows as [$r, $sku, $cost, $price, $qty, $reorder, $delivery]) {
            $item = $inventory->createItem($ctx, $ref[$r], ['tenant_sku' => $sku, 'cost_cents' => $cost * 100, 'price_cents' => $price * 100, 'quantity' => $qty, 'reorder_threshold' => $reorder,
                'store_id' => $store->id, 'supplier_id' => $supplierId, 'source' => 'manual']);
            $listing = TenantListing::query()->where('inventory_item_id', $item->id)->first();
            $inventory->updateListing($ctx, $listing->id, ['publish' => true, 'delivery_available' => $delivery && $store->supports_delivery, 'pickup_available' => true, 'lead_time_hours' => $qty === 0 ? 48 : 0]);
            $items[$r] = $item;
        }

        return $items;
    }

    /** One variant/duplicate case and one low-confidence case, produced by the real matcher over the mock provider's scenarios. */
    private function aiExamples(CatalogMatcher $matcher, Tenant $tenant, User $user): void
    {
        foreach (['makita-hp1630k' => 'Variant of an existing model', 'ambiguous-cordless-drill' => 'Low-confidence brand and model'] as $scenario => $label) {
            $result = (new MockVisionProvider)->analyze($scenario, 'image/jpeg', ['hint' => $scenario]);
            $match = $matcher->match($result->extraction, $tenant->id);
            $analysis = AiAnalysis::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'purpose' => 'intake', 'provider' => 'mock', 'model' => $result->model, 'is_mock' => true,
                'image_sha256' => hash('sha256', "seed:$scenario"), 'image_mime' => 'image/jpeg', 'extracted' => $result->extraction->toArray(), 'warnings' => array_merge($match['warnings'], [$label]),
                'raw_response' => $result->raw, 'overall_confidence' => $result->extraction->overallConfidence, 'classification' => $match['classification'], 'requires_confirmation' => true,
            ]);
            foreach ($match['candidates'] as $rank => $cand) {
                $analysis->candidates()->create(['product_id' => $cand['product_id'], 'variant_id' => $cand['variant_id'], 'classification' => $cand['classification'], 'score' => $cand['score'],
                    'matched_fields' => $cand['matched_fields'], 'mismatched_fields' => $cand['mismatched_fields'], 'warnings' => $cand['warnings'], 'rank' => $rank + 1]);
            }
        }
    }

    private function user(string $name, string $email, ?string $phone = null): User
    {
        return User::query()->forceCreate(['name' => $name, 'email' => $email, 'password' => bcrypt('password'), 'phone' => $phone, 'email_verified_at' => now()]);
    }

    private function member(Tenant $tenant, string $name, string $email, TenantRole $role, ?Store $store = null): User
    {
        $user = $this->user($name, $email);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role->value, 'store_id' => $store?->id]);

        return $user;
    }

    private function store(Tenant $t, string $name, string $code, string $addr, string $city, string $province, float $lat, float $lng, string $phone, bool $delivery): Store
    {
        $store = Store::create(['tenant_id' => $t->id, 'name' => $name, 'code' => $code, 'address_line' => $addr, 'city' => $city, 'province' => $province, 'lat' => $lat, 'lng' => $lng, 'phone' => $phone,
            'email' => strtolower($code).'@hardwhere.test', 'supports_pickup' => true, 'supports_delivery' => $delivery, 'hours' => ['mon-sat' => ['08:00', '18:00'], 'sun' => ['09:00', '15:00']]]);
        InventoryLocation::create(['tenant_id' => $t->id, 'store_id' => $store->id, 'name' => 'Main floor', 'code' => 'MAIN']);

        return $store;
    }
}
