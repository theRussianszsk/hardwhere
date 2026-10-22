<?php

use App\Services\ChangeFeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * A change-feed cursor must never land AHEAD of a transaction that is still open, or that change would be skipped forever.
 * Two real connections: A holds an uncommitted change while B commits a later one.
 */

beforeEach(fn () => Artisan::call('migrate', ['--force' => true]));
afterEach(fn () => truncateAllTables());

it('hides rows from still-open transactions and delivers them, in order, once they commit', function () {
    config(['hardwhere.sync.xmin_horizon' => true]);
    $feed = app(ChangeFeed::class);
    $a = DB::connectUsing('writer_a', config('database.connections.pgsql'));

    $a->beginTransaction();
    $a->table('sync_changes')->insert(['audience' => 'public', 'entity' => 'listing', 'entity_id' => 1]);           // lower txid, NOT yet committed
    DB::table('sync_changes')->insert(['audience' => 'public', 'entity' => 'listing', 'entity_id' => 2]);            // higher txid, committed

    $early = $feed->since(['public'], ChangeFeed::START, 100);
    expect($early['changes'])->toBe([])->and($early['cursor'])->toBe(ChangeFeed::START); // B's row is withheld: A could still commit "behind" it

    $a->commit();
    $late = $feed->since(['public'], $early['cursor'], 100);
    expect(array_column($late['changes'], 'entity_id'))->toBe([1, 2]); // A's earlier change is NOT lost
    expect($feed->since(['public'], $late['cursor'], 100)['changes'])->toBe([]);

    config(['hardwhere.sync.xmin_horizon' => false]);
    $a->disconnect();
});

it('serves committed rows immediately when nothing is in flight', function () {
    config(['hardwhere.sync.xmin_horizon' => true]);
    DB::table('sync_changes')->insert(['audience' => 'public', 'entity' => 'store', 'entity_id' => 7]);
    $r = app(ChangeFeed::class)->since(['public'], ChangeFeed::START, 10);
    expect($r['changes'])->toBe([['entity' => 'store', 'entity_id' => 7]])->and($r['cursor'])->toMatch('/^\d+\.\d+$/');
});
