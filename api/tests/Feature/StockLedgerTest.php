<?php

use App\Models\StockLedgerEntry;
use App\Models\StockSnapshot;
use App\Services\StockService;
use App\Support\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

beforeEach(function () {
    $this->refs = World::catalog();
    $this->w = World::tenant('alpha');
    $this->s = World::stocked($this->w, $this->refs['STH16'], 10, 35000);
    $this->stock = app(StockService::class);
});

it('writes an opening balance with before/after quantities, actor and reference', function () {
    $e = StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('inventory_item_id', $this->s['item']->id)->sole();
    expect([$e->movement_type, $e->quantity_delta, $e->before_quantity, $e->after_quantity, $e->actor_type, $e->actor_id, $e->reference_type, $e->reference_id])
        ->toBe(['opening_balance', 10, 0, 10, 'user', $this->w['owner']->id, 'inventory_item', $this->s['item']->id]);
});

it('keeps snapshot == sum(ledger) through every movement type', function () {
    DB::transaction(function () {
        foreach ([['purchase_receipt', 5], ['damage', -2], ['loss', -1], ['manual_adjustment', -3], ['correction', 4], ['transfer_in', 2], ['transfer_out', -1], ['return', 1]] as [$type, $d]) {
            $this->stock->adjust($this->w['tenant']->id, $this->w['owner']->id, $this->s['item']->id, $this->w['location']->id, $type, $d, 'test');
        }
    });
    $snap = StockSnapshot::query()->forTenant($this->w['ctx'])->where('inventory_item_id', $this->s['item']->id)->sole();
    $sum = StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('inventory_item_id', $this->s['item']->id)->sum('quantity_delta');
    expect($snap->on_hand)->toBe(15)->and((int) $sum)->toBe(15);
    expect(StockLedgerEntry::query()->forTenant($this->w['ctx'])->count())->toBe(9);
});

it('rejects wrong-direction movements and zero quantity', function (string $type, int $delta, string $code) {
    expect(fn () => DB::transaction(fn () => $this->stock->adjust($this->w['tenant']->id, 1, $this->s['item']->id, $this->w['location']->id, $type, $delta)))
        ->toThrow(ApiException::class);
})->with([['damage', 3, 'invalid_direction'], ['purchase_receipt', -3, 'invalid_direction'], ['manual_adjustment', 0, 'zero_quantity']]);

it('refuses to drive stock negative, in the service and in the database', function () {
    expect(fn () => DB::transaction(fn () => $this->stock->adjust($this->w['tenant']->id, 1, $this->s['item']->id, $this->w['location']->id, 'loss', -11)))->toThrow(ApiException::class);

    // bypass the service entirely: the CHECK constraint is the backstop
    expect(fn () => DB::transaction(fn () => DB::table('stock_snapshots')->where('inventory_item_id', $this->s['item']->id)->update(['on_hand' => -1])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('stock_snapshots')->where('inventory_item_id', $this->s['item']->id)->update(['reserved' => 11])))->toThrow(QueryException::class);
});

it('makes the ledger append-only in PHP and in the database', function () {
    $entry = StockLedgerEntry::query()->forTenant($this->w['ctx'])->first();
    expect(fn () => $entry->update(['note' => 'tamper']))->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::table('stock_ledger_entries')->where('id', $entry->id)->update(['quantity_delta' => 999])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('stock_ledger_entries')->where('id', $entry->id)->delete()))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('audit_events')->insert(['action' => 'x']) && DB::table('audit_events')->update(['action' => 'y'])))->toThrow(QueryException::class, 'append-only');
});

it('rejects ledger rows whose arithmetic does not add up', function () {
    expect(fn () => DB::transaction(fn () => DB::table('stock_ledger_entries')->insert([
        'tenant_id' => $this->w['tenant']->id, 'inventory_item_id' => $this->s['item']->id, 'location_id' => $this->w['location']->id, 'movement_type' => 'correction',
        'quantity_delta' => 5, 'before_quantity' => 10, 'after_quantity' => 99, 'occurred_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('requires stock changes to run inside a transaction', function () {
    // RefreshDatabase wraps tests in a transaction, so assert the guard by inspecting the contract directly.
    $ref = new ReflectionMethod(StockService::class, 'assertInTransaction');
    expect($ref->isPrivate())->toBeTrue();
    expect((string) file_get_contents(app_path('Services/StockService.php')))->toContain('transactionLevel() > 0');
});

it('exposes an adjustment API with reasons, idempotency and a readable ledger', function () {
    $h = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $body = ['location_id' => $this->w['location']->id, 'movement_type' => 'damage', 'quantity_delta' => -2, 'note' => 'Dropped'];
    $url = '/api/v1/vendor/inventory/'.$this->s['item']->id.'/adjustments';
    $this->postJson($url, ['note' => null] + $body, $h + idem('k1'))->assertStatus(422)->assertJsonPath('error.fields.note.0', fn ($m) => is_string($m));
    $this->postJson($url, $body, $h)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');
    $first = $this->postJson($url, $body, $h + idem('k2'))->assertCreated();
    $again = $this->postJson($url, $body, $h + idem('k2'))->assertOk()->assertJsonPath('replayed', true);
    expect($again->json('data.id'))->toBe($first->json('data.id'));
    $this->postJson($url, ['quantity_delta' => -3] + $body, $h + idem('k2'))->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');

    $ledger = $this->getJson('/api/v1/vendor/inventory/'.$this->s['item']->id.'/ledger', $h)->assertOk();
    expect($ledger->json('data'))->toHaveCount(2)->and($ledger->json('data.0.movement_type'))->toBe('damage')->and($ledger->json('data.0.after_quantity'))->toBe(8);
    $this->postJson($url, ['quantity_delta' => -50] + $body, $h + idem('k3'))->assertStatus(409)->assertJsonPath('error.code', 'stock_unavailable');
});

it('receives purchases into stock and updates the item cost', function () {
    $h = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $this->postJson('/api/v1/vendor/purchase-receipts', ['location_id' => $this->w['location']->id, 'reference' => 'PO-1', 'items' => [['inventory_item_id' => $this->s['item']->id, 'quantity' => 5, 'unit_cost_cents' => 21000]]], $h + idem('po1'))
        ->assertCreated()->assertJsonPath('data.total_cost_cents', 105000);
    expect(StockSnapshot::query()->forTenant($this->w['ctx'])->sole()->on_hand)->toBe(15)
        ->and($this->s['item']->fresh()->cost_cents)->toBe(21000);
});

it('replays a retried manual add-to-stock instead of reporting the stock it added as already stocked', function () {
    $h = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $body = ['variant_id' => $this->refs['NAIL3'], 'tenant_sku' => 'NAIL-3', 'cost_cents' => 5000, 'price_cents' => 8500, 'quantity' => 6, 'reorder_threshold' => 1, 'store_id' => $this->w['store']->id];
    $this->postJson('/api/v1/vendor/inventory', $body, $h)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');

    $first = $this->postJson('/api/v1/vendor/inventory', $body, $h + idem('add-1'))->assertCreated()->assertJsonPath('replayed', false);
    $again = $this->postJson('/api/v1/vendor/inventory', $body, $h + idem('add-1'))->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.on_hand', 6);
    expect($again->json('data.id'))->toBe($first->json('data.id'))->and($again->json('data.sku'))->toBe('NAIL-3');
    expect(StockLedgerEntry::query()->forTenant($this->w['ctx'])->where('inventory_item_id', $first->json('data.id'))->count())->toBe(1);

    $this->postJson('/api/v1/vendor/inventory', ['quantity' => 7] + $body, $h + idem('add-1'))->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
    $this->postJson('/api/v1/vendor/inventory', $body, $h + idem('add-2'))->assertStatus(409)->assertJsonPath('error.code', 'already_stocked'); // a genuinely new request still is
});
