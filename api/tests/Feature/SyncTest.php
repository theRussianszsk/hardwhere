<?php

use App\Enums\TenantRole;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\ChangeFeed;
use App\Services\InventoryService;
use App\Services\SyncRecorder;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

beforeEach(function () {
    config(['hardwhere.sync.xmin_horizon' => false]); // tests run inside one open transaction; see SyncHorizon test in tests/Concurrency
    $this->refs = World::catalog();
    $this->a = World::tenant('alpha');
    $this->la = World::stocked($this->a, $this->refs['STH16'], 20, 35000);
    World::customer('cust@test.test');
    $this->c = World::login($this, 'cust@test.test', 'customer');
    $this->v = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $this->svc = app(InventoryService::class);
    $this->snapshot = function (string $base, array $h, string $entity) {
        $items = [];
        $after = 0;
        $cursor = null;
        do {
            $r = $this->getJson("$base/sync/snapshot?entity=$entity&limit=2&after=$after", $h)->assertOk();
            $cursor ??= $r->json('cursor');
            $items = array_merge($items, $r->json('items'));
            $after = $r->json('next_after');
        } while ($after);

        return [$items, $cursor];
    };
});

it('serves a paged initial snapshot with a cursor taken before the data', function () {
    World::stocked($this->a, $this->refs['NAIL3'], 5, 8500);
    World::stocked($this->a, $this->refs['TLPL8'], 5, 29500);
    [$items, $cursor] = ($this->snapshot)('/api/v1/vendor', $this->v, 'inventory_item');
    expect($items)->toHaveCount(3)->and($cursor)->toMatch('/^\d+\.\d+$/');
    [$listings] = ($this->snapshot)('/api/v1/public', [], 'listing');
    expect($listings)->toHaveCount(3)->and(array_keys($listings[0]))->not->toContain('cost_cents')->and($listings[0]['availability'])->toHaveKey('status');
    $this->getJson('/api/v1/vendor/sync/snapshot?entity=bogus', $this->v)->assertStatus(422)->assertJsonPath('error.code', 'unknown_entity');
    $this->getJson('/api/v1/public/sync/snapshot?entity=inventory_item')->assertStatus(422); // customers have no such entity
});

it('delivers deltas after a cursor, and an unchanged cursor yields nothing', function () {
    [, $cursor] = ($this->snapshot)('/api/v1/public', [], 'listing');
    $this->getJson("/api/v1/public/sync/changes?cursor=$cursor")->assertOk()->assertJsonPath('changes', fn ($c) => count($c) >= 0);

    $this->svc->updateListing($this->a['ctx'], $this->la['listing']->id, ['price_cents' => 36500]);
    $d = $this->getJson("/api/v1/public/sync/changes?cursor=$cursor")->assertOk();
    $upsert = collect($d->json('changes'))->first(fn ($x) => $x['entity'] === 'listing' && $x['id'] === $this->la['listing']->id);
    expect($upsert['op'])->toBe('upsert')->and($upsert['data']['price_cents'])->toBe(36500);

    $next = $d->json('cursor');
    expect($this->getJson("/api/v1/public/sync/changes?cursor=$next")->json('changes'))->toBe([])->and($this->getJson("/api/v1/public/sync/changes?cursor=$next")->json('cursor'))->toBe($next);
});

it('sends tombstones when a listing stops being visible (unpublish, delete, closed store, suspended tenant)', function () {
    $id = $this->la['listing']->id;
    $head = fn () => app(ChangeFeed::class)->head(['catalog', 'public']);
    $op = function (string $cursor) use ($id) {
        $r = $this->getJson("/api/v1/public/sync/changes?cursor=$cursor")->assertOk();

        return collect($r->json('changes'))->first(fn ($c) => $c['entity'] === 'listing' && $c['id'] === $id);
    };

    $c0 = $head();
    $this->svc->updateListing($this->a['ctx'], $id, ['publish' => false]);
    expect($op($c0)['op'])->toBe('delete')->and($op($c0)['data'])->toBeNull();

    $this->svc->updateListing($this->a['ctx'], $id, ['publish' => true]);
    $c1 = $head();
    $this->a['tenant']->update(['status' => 'suspended']);
    app(SyncRecorder::class)->listing($this->a['tenant']->id, $id);
    expect($op($c1)['op'])->toBe('delete'); // revoked visibility propagates even though the row still exists
    $this->a['tenant']->update(['status' => 'active']);

    $c2 = $head();
    $this->la['listing']->delete();
    app(SyncRecorder::class)->listing($this->a['tenant']->id, $id);
    expect($op($c2)['op'])->toBe('delete');
});

it('pages deltas with has_more and never skips or repeats a change at a page boundary', function () {
    $start = app(ChangeFeed::class)->head(['catalog', 'public']);
    foreach (range(1, 5) as $i) {
        $this->svc->updateListing($this->a['ctx'], $this->la['listing']->id, ['price_cents' => 36000 + $i]);
    }
    $cursor = $start;
    $seen = 0;
    do {
        $r = $this->getJson("/api/v1/public/sync/changes?limit=2&cursor=$cursor")->assertOk();
        $seen += count($r->json('changes'));
        $cursor = $r->json('cursor');
    } while ($r->json('has_more'));
    expect($seen)->toBeGreaterThan(0);
    expect($this->getJson("/api/v1/public/sync/changes?cursor=$cursor")->json('changes'))->toBe([]);
    $this->getJson('/api/v1/public/sync/changes?cursor=garbage')->assertOk(); // malformed cursor => full resync from the start, never an error
});

it('applies role-aware projections for vendor devices and revokes access with the membership', function () {
    World::member($this->a['tenant'], TenantRole::Cashier, 'cash@alpha.test', $this->a['store']);
    $cash = World::login($this, 'cash@alpha.test', 'vendor', 'alpha');
    [$ownerItems] = ($this->snapshot)('/api/v1/vendor', $this->v, 'inventory_item');
    [$cashItems] = ($this->snapshot)('/api/v1/vendor', $cash, 'inventory_item');
    expect($ownerItems[0])->toHaveKey('cost_cents')->and($cashItems[0])->not->toHaveKey('cost_cents');
    // cashier has no order access: the entity is empty rather than an error
    expect($this->getJson('/api/v1/vendor/sync/snapshot?entity=order', $cash)->json('items'))->toBe([]);

    TenantMembership::query()->where('user_id', User::where('email', 'cash@alpha.test')->value('id'))->delete();
    $this->getJson('/api/v1/vendor/sync/changes?cursor=0.0', $cash)->assertStatus(403)->assertJsonPath('error.code', 'membership_revoked');
});

it('syncs a customer\'s own orders only', function () {
    World::customer('other@test.test');
    $o = World::login($this, 'other@test.test', 'customer');
    $this->postJson('/api/v1/customer/orders', ['items' => [['listing_id' => $this->la['listing']->id, 'quantity' => 1]], 'fulfillment_type' => 'pickup', 'contact_name' => 'C', 'contact_phone' => '09171234567'], $this->c + idem())->assertCreated();
    [$mine] = ($this->snapshot)('/api/v1/customer', $this->c, 'order');
    [$theirs] = ($this->snapshot)('/api/v1/customer', $o, 'order');
    expect($mine)->toHaveCount(1)->and($theirs)->toBe([])->and($mine[0]['status'])->toBe('reserved');
    $changes = $this->getJson('/api/v1/customer/sync/changes?cursor=0.0', $o)->json('changes');
    expect(collect($changes)->where('entity', 'order')->all())->toBe([]);
    $this->getJson('/api/v1/customer/sync/snapshot?entity=order', $this->v)->assertForbidden(); // vendor token on the customer feed
});

it('never lets a cursor grant access: a stale cursor with revoked rights still returns tombstones, not data', function () {
    $id = $this->la['listing']->id;
    $this->la['listing']->update(['visibility' => 'hidden']);
    app(SyncRecorder::class)->listing($this->a['tenant']->id, $id);
    $r = $this->getJson('/api/v1/public/sync/changes?cursor=0.0')->json('changes');
    $mine = collect($r)->where('entity', 'listing')->where('id', $id)->last();
    expect($mine['op'])->toBe('delete')->and($mine['data'])->toBeNull();
    expect(DB::table('sync_changes')->count())->toBeGreaterThan(0);
});
