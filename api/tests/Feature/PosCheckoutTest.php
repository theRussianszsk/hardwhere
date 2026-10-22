<?php

use App\Enums\TenantRole;
use App\Models\Sale;
use App\Models\StockLedgerEntry;
use App\Models\StockSnapshot;
use App\Services\InventoryService;
use App\Services\Vat;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

beforeEach(function () {
    $this->refs = World::catalog();
    $this->w = World::tenant('alpha');
    $this->a = World::stocked($this->w, $this->refs['STH16'], 10, 35000);   // ₱350.00
    $this->b = World::stocked($this->w, $this->refs['NAIL3'], 3, 8500);
    World::member($this->w['tenant'], TenantRole::Cashier, 'cashier@alpha.test', $this->w['store']);
    $this->cashier = World::login($this, 'cashier@alpha.test', 'vendor', 'alpha');
    $this->cart = fn (array $items, array $pay = ['method' => 'cash', 'tendered_cents' => 1000000], array $extra = []) => ['store_id' => $this->w['store']->id, 'items' => $items, 'payment' => $pay] + $extra;
    $this->line = fn ($s, int $q = 1, array $x = []) => ['listing_id' => $s['listing']->id, 'quantity' => $q] + $x;
    $this->snap = fn ($s) => StockSnapshot::query()->forTenant($this->w['ctx'])->where('inventory_item_id', $s['item']->id)->sole();
});

it('creates sale, items, ledger entries and decrements the snapshot atomically', function () {
    $r = $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 2), ($this->line)($this->b, 1)], ['method' => 'cash', 'tendered_cents' => 100000], ['customer' => ['name' => 'Juan', 'phone' => '0917 000 0000']]), $this->cashier + idem())->assertCreated();
    $total = 2 * 35000 + 8500;
    expect($r->json('data.total_cents'))->toBe($total)->and($r->json('data.change_cents'))->toBe(100000 - $total)->and($r->json('data.vat_cents'))->toBe(Vat::includedIn(70000, 1200) + Vat::includedIn(8500, 1200))
        ->and($r->json('data.items'))->toHaveCount(2)->and($r->json('data.customer_name'))->toBe('Juan')->and($r->json('data.receipt_no'))->toMatch('/^ALP-1-\d{6}-\d{6}$/');
    expect(($this->snap)($this->a)->on_hand)->toBe(8)->and(($this->snap)($this->b)->on_hand)->toBe(2);
    $sold = StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('movement_type', 'sale')->get();
    expect($sold)->toHaveCount(2)->and($sold->every(fn ($e) => $e->reference_type === 'sale' && $e->reference_id === $r->json('data.id') && $e->actor_id !== null))->toBeTrue();
    expect(Sale::query()->forTenant($this->w['ctx'])->sole()->items->sum('unit_cost_cents'))->toBeGreaterThan(0); // margin snapshot kept internally...
    expect(json_encode($r->json()))->not->toContain('unit_cost');                                                  // ...never serialized
});

it('does not touch stock until checkout succeeds, and a failed checkout changes nothing', function () {
    // Searching / building a cart is read-only
    $this->getJson('/api/v1/vendor/pos/search?store_id='.$this->w['store']->id.'&q=hammer', $this->cashier)->assertOk();
    expect(($this->snap)($this->a)->on_hand)->toBe(10);

    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 2), ($this->line)($this->b, 99)]), $this->cashier + idem())
        ->assertStatus(409)->assertJsonPath('error.code', 'stock_unavailable')->assertJsonPath('error.details.available', 3)->assertJsonPath('error.details.requested', 99);
    expect(($this->snap)($this->a)->on_hand)->toBe(10)->and(Sale::query()->forTenant($this->w['ctx'])->count())->toBe(0)
        ->and(StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('movement_type', 'sale')->count())->toBe(0);
});

it('reports price conflicts instead of silently using a stale price', function () {
    $this->a['listing']->update(['price_cents' => 36000]);
    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1, ['expected_unit_price_cents' => 35000])]), $this->cashier + idem())
        ->assertStatus(409)->assertJsonPath('error.code', 'price_changed')->assertJsonPath('error.details.current', 36000)->assertJsonPath('error.details.expected', 35000);
});

it('uses the sale price when one is set', function () {
    app(InventoryService::class)->updateListing($this->w['ctx'], $this->a['listing']->id, ['sale_price_cents' => 30000]);
    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1)]), $this->cashier + idem())->assertCreated()->assertJsonPath('data.total_cents', 30000);
});

it('validates payment: cash tender, references for non-cash, and never accepts card numbers', function () {
    $post = fn (array $pay) => $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1)], $pay), $this->cashier + idem());
    $post(['method' => 'cash', 'tendered_cents' => 1000])->assertStatus(422)->assertJsonPath('error.code', 'insufficient_tender');
    $post(['method' => 'gcash'])->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['payment.reference']));
    $post(['method' => 'card', 'reference' => '4111 1111 1111 1111'])->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['payment.reference']));
    $post(['method' => 'card', 'reference' => 'SLIP-884213'])->assertCreated()->assertJsonPath('data.payment_reference', 'SLIP-884213');
    expect(DB::table('sales')->where('payment_reference', 'like', '4111%')->count())->toBe(0);
});

it('has exactly-once effect when a checkout is retried, and rejects key reuse with a different cart', function () {
    $key = idem('retry-1');
    $body = ($this->cart)([($this->line)($this->a, 3)]);
    $first = $this->postJson('/api/v1/vendor/pos/checkout', $body, $this->cashier + $key)->assertCreated();
    $retry = $this->postJson('/api/v1/vendor/pos/checkout', $body, $this->cashier + $key)->assertOk()->assertJsonPath('replayed', true);
    expect($retry->json('data.id'))->toBe($first->json('data.id'))->and(($this->snap)($this->a)->on_hand)->toBe(7)->and(Sale::query()->forTenant($this->w['ctx'])->count())->toBe(1);
    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1)]), $this->cashier + $key)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
    $this->postJson('/api/v1/vendor/pos/checkout', $body, $this->cashier)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');
});

it('merges duplicate lines and totals discounts', function () {
    $owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $r = $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1, ['discount_cents' => 1000]), ($this->line)($this->a, 2)]), $owner + idem())->assertCreated();
    expect($r->json('data.items'))->toHaveCount(1)->and($r->json('data.items.0.quantity'))->toBe(3)->and($r->json('data.discount_cents'))->toBe(1000)->and($r->json('data.total_cents'))->toBe(3 * 35000 - 1000);
    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1, ['discount_cents' => 99999])]), $owner + idem())->assertStatus(422)->assertJsonPath('error.code', 'discount_too_large');
});

it('warns about low stock after a sale', function () {
    $r = $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->b, 2)]), $this->cashier + idem())->assertCreated();
    expect($r->json('data.low_stock.0'))->toMatchArray(['sku' => $this->b['item']->tenant_sku, 'remaining' => 1, 'reorder_threshold' => 2]);
});

it('voids a sale, returning stock through the ledger, and refuses a second void', function () {
    $owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $id = $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 4)]), $owner + idem())->assertCreated()->json('data.id');
    expect(($this->snap)($this->a)->on_hand)->toBe(6);
    $this->postJson("/api/v1/vendor/sales/$id/void", ['reason' => 'Wrong item'], $this->cashier)->assertForbidden();
    $this->postJson("/api/v1/vendor/sales/$id/void", ['reason' => 'Wrong item'], $owner)->assertOk()->assertJsonPath('data.status', 'voided')->assertJsonPath('data.items.0.returned_quantity', 4);
    expect(($this->snap)($this->a)->on_hand)->toBe(10)->and(StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('movement_type', 'return')->count())->toBe(1);
    $this->postJson("/api/v1/vendor/sales/$id/void", ['reason' => 'again'], $owner)->assertStatus(409)->assertJsonPath('error.code', 'already_voided');
});

it('lets cashiers see only their own sales and tracks register sessions', function () {
    $owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $mine = $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1)]), $this->cashier + idem())->assertCreated()->json('data.id');
    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1)]), $owner + idem())->assertCreated();
    expect($this->getJson('/api/v1/vendor/sales', $this->cashier)->json('data'))->toHaveCount(1)->and($this->getJson('/api/v1/vendor/sales', $owner)->json('data'))->toHaveCount(2);
    $this->getJson("/api/v1/vendor/sales/$mine", $this->cashier)->assertOk();

    $session = $this->postJson('/api/v1/vendor/pos/sessions', ['register_id' => $this->w['register']->id, 'opening_float_cents' => 50000], $this->cashier)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/vendor/pos/sessions', ['register_id' => $this->w['register']->id], $this->cashier)->assertStatus(409)->assertJsonPath('error.code', 'session_open');
    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1)], ['method' => 'cash', 'tendered_cents' => 99999], ['pos_session_id' => $session]), $this->cashier + idem())->assertCreated();
    $this->postJson("/api/v1/vendor/pos/sessions/$session/close", ['closing_cash_cents' => 85000], $this->cashier)->assertOk();
    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 1)], ['method' => 'cash', 'tendered_cents' => 99999], ['pos_session_id' => $session]), $this->cashier + idem())->assertStatus(422)->assertJsonPath('error.code', 'session_closed');
});

it('lets POS sell items that are not yet published online', function () {
    $draft = World::stocked($this->w, $this->refs['TAPE5'], 5, 23500, publish: false);
    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($draft, 1)]), $this->cashier + idem())->assertCreated();
});

it('looks up the outcome of a checkout by its key without re-sending it, scoped to the cashier', function () {
    $owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $status = fn (string $key, array $who) => $this->getJson("/api/v1/vendor/pos/checkout/$key", $who);
    $sale = $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->a, 2)]), $this->cashier + idem('lookup-1'))->assertCreated();

    $status('test-key-lookup-1', $this->cashier)->assertOk()->assertJsonPath('data.id', $sale->json('data.id'))->assertJsonPath('data.total_cents', 70000)->assertJsonPath('data.items.0.quantity', 2);
    $status('test-key-lookup-1', $this->cashier)->assertOk();
    expect(($this->snap)($this->a)->on_hand)->toBe(8)->and(Sale::query()->forTenant($this->w['ctx'])->count())->toBe(1); // reading twice sold nothing more

    $status('test-key-never-sent', $this->cashier)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $status('test-key-lookup-1', $owner)->assertNotFound(); // keys are per cashier: the owner's identical key is a different attempt
    $this->postJson('/api/v1/vendor/pos/checkout', ($this->cart)([($this->line)($this->b, 99)]), $this->cashier + idem('lookup-refused'))->assertStatus(409);
    $status('test-key-lookup-refused', $this->cashier)->assertNotFound(); // a refused checkout recorded nothing
    $this->getJson('/api/v1/vendor/pos/checkout/bad', $this->cashier)->assertNotFound();
});
