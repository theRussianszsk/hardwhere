<?php

use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\StockLedgerEntry;
use App\Models\StockSnapshot;
use App\Models\Store;
use App\Models\TenantListing;
use App\Services\InventoryService;
use App\Services\ReservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\World;

beforeEach(function () {
    $this->refs = World::catalog();
    $this->w = World::tenant('alpha');
    $this->s = World::stocked($this->w, $this->refs['STH16'], 10, 35000);
    $this->ana = World::customer('ana@test.test');
    $this->ben = World::customer('ben@test.test');
    $this->h = World::login($this, 'ana@test.test', 'customer');
    $this->hb = World::login($this, 'ben@test.test', 'customer');
    $this->owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $this->body = fn (int $qty = 1, array $x = []) => $x + ['items' => [['listing_id' => $this->s['listing']->id, 'quantity' => $qty]], 'fulfillment_type' => 'pickup', 'contact_name' => 'Ana', 'contact_phone' => '09171234567'];
    $this->place = fn (array $h, int $qty = 1, array $x = [], ?string $key = null) => $this->postJson('/api/v1/customer/orders', ($this->body)($qty, $x), $h + idem($key ?? ''));
    $this->snap = fn () => StockSnapshot::query()->forTenant($this->w['ctx'])->where('inventory_item_id', $this->s['item']->id)->sole();
    $this->move = fn (int $orderId, string $to, ?array $h = null) => $this->postJson("/api/v1/vendor/orders/$orderId/transition", ['to' => $to, 'reason' => 'x'], $h ?? $this->owner);
});

it('reserves stock atomically when an order is placed (on hand unchanged, available reduced)', function () {
    $r = ($this->place)($this->h, 3)->assertCreated();
    expect($r->json('data.status'))->toBe('reserved')->and($r->json('data.order_no'))->toStartWith('HW-')->and($r->json('data.total_cents'))->toBe(105000)
        ->and($r->json('data.reservation.status'))->toBe('active')->and($r->json('data.customer_can_cancel'))->toBeTrue()
        ->and($r->json('data.contact_phone'))->toBe('+639171234567');
    $s = ($this->snap)();
    expect([$s->on_hand, $s->reserved])->toBe([10, 3]);
    $e = StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('movement_type', 'reservation')->sole();
    expect([$e->quantity_delta, $e->reserved_delta, $e->before_reserved, $e->after_reserved, $e->reference_type])->toBe([0, 3, 0, 3, 'order']);
    $avail = $this->getJson('/api/v1/public/listings/'.$this->s['listing']->id)->assertOk()->json('data.availability');
    expect($avail)->toBe(['status' => 'in_stock', 'quantity' => null]); // 7 left, exact count hidden
});

it('never lets two customers reserve the last units', function () {
    ($this->place)($this->h, 8)->assertCreated();
    // the customer learns which line is short and the public bucket, never the exact count (store hides it) or internal ids
    ($this->place)($this->hb, 3)->assertStatus(409)->assertJsonPath('error.code', 'stock_unavailable')
        ->assertExactJson(['error' => ['code' => 'stock_unavailable', 'message' => 'Not enough stock is available.', 'details' => ['listing_id' => $this->s['listing']->id, 'requested' => 3, 'availability' => 'low_stock']]]);
    ($this->place)($this->hb, 2)->assertCreated();
    ($this->place)($this->hb, 1)->assertStatus(409);
    $s = ($this->snap)();
    expect([$s->on_hand, $s->reserved])->toBe([10, 10]);
    $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $this->w['store']->id, 'items' => [['listing_id' => $this->s['listing']->id, 'quantity' => 1]], 'payment' => ['method' => 'cash', 'tendered_cents' => 99999]], $this->owner + idem())
        ->assertStatus(409)->assertJsonPath('error.details.available', 0); // POS respects marketplace reservations
});

it('is idempotent: a retried order has one effect', function () {
    $first = ($this->place)($this->h, 2, [], 'order-retry-1')->assertCreated();
    $again = ($this->place)($this->h, 2, [], 'order-retry-1')->assertOk()->assertJsonPath('replayed', true);
    expect($again->json('data.id'))->toBe($first->json('data.id'))->and(Order::query()->forTenant($this->w['ctx'])->count())->toBe(1)->and(($this->snap)()->reserved)->toBe(2);
    ($this->place)($this->h, 5, [], 'order-retry-1')->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('enforces the order state machine', function () {
    $id = ($this->place)($this->h)->json('data.id');
    ($this->move)($id, 'fulfilled')->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    ($this->move)($id, 'preparing')->assertStatus(409);
    foreach (['confirmed', 'preparing', 'ready_for_pickup', 'fulfilled'] as $to) {
        ($this->move)($id, $to)->assertOk()->assertJsonPath('data.status', $to);
    }
    ($this->move)($id, 'cancelled')->assertStatus(409);
    expect(OrderStatus::Pending->canMoveTo(OrderStatus::Reserved))->toBeTrue()
        ->and(OrderStatus::Fulfilled->next())->toBe([])
        ->and(OrderStatus::Reserved->canMoveTo(OrderStatus::Preparing))->toBeFalse()
        ->and(OrderStatus::Preparing->customerCancellable())->toBeFalse();
});

it('consumes the reserved units on fulfilment and records order_fulfillment', function () {
    $id = ($this->place)($this->h, 4)->json('data.id');
    foreach (['confirmed', 'preparing', 'ready_for_pickup', 'fulfilled'] as $to) {
        ($this->move)($id, $to)->assertOk();
    }
    $s = ($this->snap)();
    expect([$s->on_hand, $s->reserved])->toBe([6, 0]);
    $e = StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('movement_type', 'order_fulfillment')->sole();
    expect([$e->quantity_delta, $e->reserved_delta])->toBe([-4, -4]);
    expect(Reservation::query()->forTenant($this->w['ctx'])->sole()->status)->toBe('converted');
});

it('lets customers cancel only while the store has not started preparing, and releases the hold', function () {
    $id = ($this->place)($this->h, 5)->json('data.id');
    $this->postJson("/api/v1/customer/orders/$id/cancel", ['reason' => 'Changed mind'], $this->hb)->assertNotFound(); // not their order
    ($this->move)($id, 'confirmed')->assertOk();
    $this->postJson("/api/v1/customer/orders/$id/cancel", ['reason' => 'Changed mind'], $this->h)->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.reservation.status', 'cancelled');
    $s = ($this->snap)();
    expect([$s->on_hand, $s->reserved])->toBe([10, 0]);
    expect(StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('movement_type', 'reservation_release')->sole()->reserved_delta)->toBe(-5);
    $this->postJson("/api/v1/customer/orders/$id/cancel", [], $this->h)->assertStatus(409);

    $id2 = ($this->place)($this->h, 1)->json('data.id');
    ($this->move)($id2, 'confirmed');
    ($this->move)($id2, 'preparing')->assertOk();
    $this->postJson("/api/v1/customer/orders/$id2/cancel", [], $this->h)->assertStatus(409)->assertJsonPath('error.code', 'not_cancellable');
    ($this->move)($id2, 'cancelled')->assertOk(); // the store can still cancel
    expect(($this->snap)()->reserved)->toBe(0);
});

it('expires overdue reservations via the scheduled command and frees stock', function () {
    $id = ($this->place)($this->h, 6)->json('data.id');
    Reservation::query()->where('order_id', $id)->update(['expires_at' => now()->subMinute()]);
    expect(Artisan::call('reservations:expire'))->toBe(0);
    expect(Order::find($id)->status)->toBe('expired')->and(Reservation::query()->where('order_id', $id)->value('status'))->toBe('expired')->and(($this->snap)()->reserved)->toBe(0);
    $this->getJson("/api/v1/customer/orders/$id", $this->h)->assertOk()->assertJsonPath('data.status', 'expired')->assertJsonPath('data.customer_can_cancel', false);
    ($this->move)($id, 'confirmed')->assertStatus(409);
    // running it again is a no-op
    Artisan::call('reservations:expire');
    expect(StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('movement_type', 'reservation_release')->count())->toBe(1);
});

it('does not let an overdue, not-yet-swept reservation block the next buyer', function () {
    $id = ($this->place)($this->h, 10)->json('data.id');
    ($this->place)($this->hb, 1)->assertStatus(409);
    Reservation::query()->where('order_id', $id)->update(['expires_at' => now()->subSecond()]);
    ($this->place)($this->hb, 1)->assertCreated(); // checkout releases overdue holds for the items it touches
    expect(Order::find($id)->status)->toBe('expired')->and(($this->snap)()->reserved)->toBe(1);
});

it('does not auto-expire an order the store is already preparing', function () {
    $id = ($this->place)($this->h, 2)->json('data.id');
    ($this->move)($id, 'confirmed');
    ($this->move)($id, 'preparing');
    Reservation::query()->where('order_id', $id)->update(['expires_at' => now()->subHour()]);
    app(ReservationService::class)->expireDue();
    expect(Order::find($id)->status)->toBe('preparing')->and(($this->snap)()->reserved)->toBe(2);
});

it('gives a confirmed or ready order a fresh pickup window instead of the initial hold', function () {
    config(['hardwhere.marketplace.pickup_hold_minutes' => 60 * 24]);
    $id = ($this->place)($this->h, 2)->json('data.id'); // initial hold: tenant reservation_ttl_minutes (120)
    ($this->move)($id, 'confirmed')->assertOk();
    $this->travel(20)->hours(); // long past the initial 2-hour hold
    expect(app(ReservationService::class)->expireDue())->toBe(0);
    ($this->move)($id, 'preparing')->assertOk();
    ($this->move)($id, 'ready_for_pickup')->assertOk(); // the window restarts when the item is bagged
    $this->travel(10)->hours();
    expect(app(ReservationService::class)->expireDue())->toBe(0)
        ->and(Order::find($id)->status)->toBe('ready_for_pickup')->and(($this->snap)()->reserved)->toBe(2);
    $this->getJson("/api/v1/customer/orders/$id", $this->h)->assertOk()
        ->assertJsonPath('data.reservation.expires_at', fn ($at) => abs(now()->diffInMinutes(Carbon::parse($at)) - 14 * 60) < 2);
    $this->travel(15)->hours(); // an uncollected order still lapses eventually
    expect(app(ReservationService::class)->expireDue())->toBe(1)->and(($this->snap)()->reserved)->toBe(0);
});

it('validates the order against the listing rules', function () {
    app(InventoryService::class)->updateListing($this->w['ctx'], $this->s['listing']->id, ['min_order_qty' => 2, 'max_order_qty' => 4, 'stock_buffer' => 3]);
    ($this->place)($this->h, 1)->assertStatus(422)->assertJsonPath('error.code', 'quantity_out_of_range');
    ($this->place)($this->h, 5)->assertStatus(422)->assertJsonPath('error.code', 'quantity_out_of_range');
    ($this->place)($this->h, 4, ['items' => [['listing_id' => $this->s['listing']->id, 'quantity' => 4, 'expected_unit_price_cents' => 1]]])->assertStatus(409)->assertJsonPath('error.code', 'price_changed');
    ($this->place)($this->h, 4)->assertCreated(); // stock_buffer 3 leaves 7 available to the marketplace; 4 fits
    ($this->place)($this->hb, 4)->assertStatus(409)->assertJsonPath('error.details.availability', 'low_stock')->assertJsonMissingPath('error.details.available');
    app(InventoryService::class)->updateListing($this->w['ctx'], $this->s['listing']->id, ['show_exact_stock' => true]); // the store opts in: exact count is fair game
    ($this->place)($this->hb, 4)->assertStatus(409)->assertJsonPath('error.details.available', 3)->assertJsonMissingPath('error.details.inventory_item_id');
    ($this->place)($this->h, 2, ['contact_phone' => '12345'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_phone');
    ($this->place)($this->h, 2, ['fulfillment_type' => 'delivery'])->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['delivery_address']));
    ($this->place)($this->h, 2, ['fulfillment_type' => 'delivery', 'delivery_address' => '1 Rizal St, QC'])->assertCreated();
});

it('rejects hidden listings, unsupported fulfilment, and multi-store carts', function () {
    $second = Store::create(['tenant_id' => $this->w['tenant']->id, 'name' => 'Branch', 'code' => 'BR2', 'address_line' => 'x', 'city' => 'y', 'province' => 'z', 'supports_delivery' => false]);
    InventoryLocation::create(['tenant_id' => $this->w['tenant']->id, 'store_id' => $second->id, 'name' => 'Main', 'code' => 'MAIN']);
    $other = TenantListing::create(['tenant_id' => $this->w['tenant']->id, 'store_id' => $second->id, 'inventory_item_id' => $this->s['item']->id, 'product_id' => $this->s['listing']->product_id,
        'variant_id' => $this->s['listing']->variant_id, 'tenant_sku' => 'X', 'price_cents' => 100, 'visibility' => 'public', 'publication_status' => 'published']);
    ($this->place)($this->h, 1, ['items' => [['listing_id' => $this->s['listing']->id, 'quantity' => 1], ['listing_id' => $other->id, 'quantity' => 1]]])->assertStatus(422)->assertJsonPath('error.code', 'mixed_stores');
    ($this->place)($this->h, 1, ['items' => [['listing_id' => $other->id, 'quantity' => 1]], 'fulfillment_type' => 'delivery', 'delivery_address' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'delivery_unsupported');

    app(InventoryService::class)->updateListing($this->w['ctx'], $this->s['listing']->id, ['publish' => false]);
    ($this->place)($this->h)->assertStatus(409)->assertJsonPath('error.code', 'listing_unavailable');
    expect(($this->snap)()->reserved)->toBe(0);
});

it('shows customers only their own orders and vendors only their tenant\'s', function () {
    $id = ($this->place)($this->h)->json('data.id');
    $this->getJson("/api/v1/customer/orders/$id", $this->hb)->assertNotFound();
    expect($this->getJson('/api/v1/customer/orders', $this->hb)->json('data'))->toBe([]);
    expect($this->getJson('/api/v1/customer/orders', $this->h)->json('data'))->toHaveCount(1);
    $other = World::tenant('beta');
    $b = World::login($this, 'owner@beta.test', 'vendor', 'beta');
    $this->getJson("/api/v1/vendor/orders/$id", $b)->assertNotFound();
    $this->postJson("/api/v1/vendor/orders/$id/transition", ['to' => 'confirmed'], $b)->assertNotFound();
    expect($this->getJson('/api/v1/vendor/orders', $b)->json('data'))->toBe([]);
});

it('keeps a store-bound manager away from other stores\' orders', function () {
    $branch = Store::create(['tenant_id' => $this->w['tenant']->id, 'name' => 'Branch', 'code' => 'BR3', 'address_line' => 'x', 'city' => 'y', 'province' => 'z']);
    World::member($this->w['tenant'], TenantRole::StoreManager, 'mgr@alpha.test', $branch);
    $mgr = World::login($this, 'mgr@alpha.test', 'vendor', 'alpha');
    $id = ($this->place)($this->h)->json('data.id');
    $this->getJson("/api/v1/vendor/orders/$id", $mgr)->assertForbidden();
    expect($this->getJson('/api/v1/vendor/orders', $mgr)->json('data'))->toBe([]);
});
