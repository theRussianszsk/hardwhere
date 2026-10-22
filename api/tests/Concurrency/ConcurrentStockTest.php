<?php

use App\Models\StockSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\World;

/*
 * Real concurrency: N separate PHP processes, each with its own PostgreSQL connection, race for the same stock.
 * Data is COMMITTED (no wrapping test transaction) so the workers can see it; tables are truncated afterwards.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    $this->refs = World::catalog();
    $this->w = World::tenant('race');
});

afterEach(fn () => truncateAllTables());

/** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
function race(array $jobs): array
{
    $procs = array_map(function ($job) {
        $p = new Process([PHP_BINARY, __DIR__.'/../Support/concurrent_worker.php', json_encode($job)], null, [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'hardwhere_test', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4',
        ]);
        $p->setTimeout(120);
        $p->start();

        return $p;
    }, $jobs);
    $out = [];
    foreach ($procs as $p) {
        $p->wait();
        $out[] = json_decode(trim($p->getOutput()), true) ?? ['ok' => false, 'code' => 'no-output', 'message' => $p->getErrorOutput()];
    }

    return $out;
}

function posJob(array $w, int $listingId, int $qty, string $key): array
{
    return ['kind' => 'pos', 'tenant' => $w['tenant']->id, 'user' => $w['owner']->id, 'key' => $key,
        'data' => ['store_id' => $w['store']->id, 'items' => [['listing_id' => $listingId, 'quantity' => $qty]], 'payment' => ['method' => 'cash', 'tendered_cents' => 99999999]]];
}

function orderJob(int $userId, int $listingId, int $qty, string $key): array
{
    return ['kind' => 'order', 'user' => $userId, 'key' => $key, 'data' => ['items' => [['listing_id' => $listingId, 'quantity' => $qty]], 'fulfillment_type' => 'pickup', 'contact_name' => 'X', 'contact_phone' => '+639171234567']];
}

function ledgerMatchesSnapshot(int $itemId): void
{
    $snap = StockSnapshot::query()->withoutGlobalScopes()->where('inventory_item_id', $itemId)->get();
    foreach ($snap as $s) {
        $sum = (int) DB::table('stock_ledger_entries')->where('inventory_item_id', $itemId)->where('location_id', $s->location_id)->sum('quantity_delta');
        $res = (int) DB::table('stock_ledger_entries')->where('inventory_item_id', $itemId)->where('location_id', $s->location_id)->sum('reserved_delta');
        expect([$s->on_hand, $s->reserved])->toBe([$sum, $res])->and($s->on_hand)->toBeGreaterThanOrEqual(0)->and($s->reserved)->toBeLessThanOrEqual($s->on_hand);
    }
}

it('never oversells: 14 simultaneous POS checkouts for 5 units sell exactly 5', function () {
    $s = World::stocked($this->w, $this->refs['STH16'], 5, 35000);
    $results = race(array_map(fn ($i) => posJob($this->w, $s['listing']->id, 1, "pos-race-$i-aaaa"), range(1, 14)));

    $ok = collect($results)->where('ok', true);
    $failed = collect($results)->where('ok', false);
    expect($ok)->toHaveCount(5)->and($failed->pluck('code')->unique()->all())->toBe(['stock_unavailable']);
    expect(StockSnapshot::query()->withoutGlobalScopes()->where('inventory_item_id', $s['item']->id)->value('on_hand'))->toBe(0);
    expect(DB::table('sales')->count())->toBe(5)->and(DB::table('stock_ledger_entries')->where('movement_type', 'sale')->count())->toBe(5);
    ledgerMatchesSnapshot($s['item']->id);
});

it('never double-reserves: 12 simultaneous marketplace orders for 4 units reserve exactly 4', function () {
    $s = World::stocked($this->w, $this->refs['STH16'], 4, 35000);
    $users = collect(range(1, 12))->map(fn ($i) => World::customer("c$i@race.test")->id);
    $results = race($users->map(fn ($uid, $i) => orderJob($uid, $s['listing']->id, 1, "ord-race-$i-aaaa"))->values()->all());

    expect(collect($results)->where('ok', true))->toHaveCount(4)->and(collect($results)->where('ok', false)->pluck('code')->unique()->all())->toBe(['stock_unavailable']);
    $snap = StockSnapshot::query()->withoutGlobalScopes()->where('inventory_item_id', $s['item']->id)->sole();
    expect([$snap->on_hand, $snap->reserved])->toBe([4, 4])->and(DB::table('reservations')->where('status', 'active')->count())->toBe(4);
    ledgerMatchesSnapshot($s['item']->id);
});

it('shares the same stock fairly between POS sales and marketplace reservations', function () {
    $s = World::stocked($this->w, $this->refs['STH16'], 6, 35000);
    $jobs = [];
    foreach (range(1, 6) as $i) {
        $jobs[] = posJob($this->w, $s['listing']->id, 1, "mix-pos-$i-aaaa");
        $jobs[] = orderJob(World::customer("m$i@race.test")->id, $s['listing']->id, 1, "mix-ord-$i-aaaa");
    }
    $results = race($jobs);

    expect(collect($results)->where('ok', true))->toHaveCount(6)->and(collect($results)->where('ok', false)->pluck('code')->unique()->all())->toBe(['stock_unavailable']);
    $snap = StockSnapshot::query()->withoutGlobalScopes()->where('inventory_item_id', $s['item']->id)->sole();
    expect($snap->on_hand + 0)->toBe(6 - DB::table('sales')->count())->and($snap->reserved)->toBe(DB::table('reservations')->where('status', 'active')->count())
        ->and($snap->on_hand - $snap->reserved)->toBe(0);
    ledgerMatchesSnapshot($s['item']->id);
});

it('executes a duplicated command once: 8 simultaneous retries of one idempotency key', function () {
    $s = World::stocked($this->w, $this->refs['STH16'], 10, 35000);
    $results = race(array_fill(0, 8, posJob($this->w, $s['listing']->id, 3, 'same-key-retry-1')));

    expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue()->and(collect($results)->pluck('id')->unique())->toHaveCount(1)->and(collect($results)->where('replayed', false))->toHaveCount(1);
    expect(DB::table('sales')->count())->toBe(1)->and(StockSnapshot::query()->withoutGlobalScopes()->where('inventory_item_id', $s['item']->id)->value('on_hand'))->toBe(7);
    expect(DB::table('idempotency_keys')->where('key', 'same-key-retry-1')->count())->toBe(1);
    ledgerMatchesSnapshot($s['item']->id);
});

it('does not deadlock when carts list the same items in opposite order', function () {
    $a = World::stocked($this->w, $this->refs['STH16'], 40, 35000);
    $b = World::stocked($this->w, $this->refs['NAIL3'], 40, 8500);
    $jobs = [];
    foreach (range(1, 16) as $i) {
        $items = [['listing_id' => $a['listing']->id, 'quantity' => 1], ['listing_id' => $b['listing']->id, 'quantity' => 1]];
        $job = posJob($this->w, $a['listing']->id, 1, "dl-$i-aaaaaaaa");
        $job['data']['items'] = $i % 2 ? $items : array_reverse($items);
        $jobs[] = $job;
    }
    $results = race($jobs);

    expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue(json_encode($results));
    expect(DB::table('sales')->count())->toBe(16);
    foreach ([$a, $b] as $x) {
        expect(StockSnapshot::query()->withoutGlobalScopes()->where('inventory_item_id', $x['item']->id)->value('on_hand'))->toBe(24);
        ledgerMatchesSnapshot($x['item']->id);
    }
});
