<?php

use App\Models\TenantListing;
use App\Services\SyncRecorder;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\Support\World;

/*
 * The mobile apps' contract with this server.
 *  1. endpoints.json (what the TypeScript client calls) must match the real router, in both directions.
 *  2. Real responses are written to packages/shared/contract/fixtures; `tsc` type-checks them against the shared DTO types,
 *     so server/client drift fails the TypeScript build instead of failing in a customer's hand.
 */

function manifest(): array
{
    return json_decode(file_get_contents(base_path('../packages/shared/contract/endpoints.json')), true);
}

it('lists only endpoints that exist, with the declared method and auth', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'));
    foreach (manifest()['endpoints'] as $e) {
        $uri = 'api/v1/'.$e['path'];
        $route = $routes->first(fn ($r) => $r->uri() === $uri && in_array($e['method'], $r->methods(), true));
        expect($route)->not->toBeNull("{$e['name']}: {$e['method']} $uri does not exist");

        $mw = $route->gatherMiddleware();
        match ($e['auth']) {
            'none' => expect($mw)->not->toContain('auth:sanctum'),
            'customer' => expect($mw)->toContain('auth:sanctum')->toContain('abilities:customer'),
            'vendor' => expect($mw)->toContain('auth:sanctum')->toContain('abilities:vendor')->toContain('tenant'),
            'any' => expect($mw)->toContain('auth:sanctum'),
        };
    }
});

it('has no undeclared public API surface: every v1 route is in the manifest or deliberately server-only', function () {
    $declared = collect(manifest()['endpoints'])->map(fn ($e) => $e['method'].' api/v1/'.$e['path']);
    $serverOnly = fn (string $uri) => str_starts_with($uri, 'api/v1/admin/') || $uri === 'api/v1/health';
    $missing = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && ! $serverOnly($r->uri()))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS']))->map(fn ($m) => "$m {$r->uri()}"))
        ->reject(fn ($k) => $declared->contains($k))->values();
    expect($missing->all())->toBe([], 'Add these routes to packages/shared/contract/endpoints.json (and the TS client): '.$missing->implode(', '));
});

/** Make fixtures stable across runs: fixed ids/timestamps/tokens, at most two list items. Decoded as objects so `{}` stays `{}`. */
function normalizeFixture(mixed $v, ?string $key = null): mixed
{
    if (is_array($v)) {
        return array_map(fn ($x) => normalizeFixture($x, $key), array_slice($v, 0, 2));
    }
    if ($v instanceof stdClass) {
        $out = new stdClass;
        foreach (get_object_vars($v) as $k => $x) {
            $out->{$k} = normalizeFixture($x, (string) $k);
        }

        return $out;
    }
    if (is_int($v) && $key !== null && ($key === 'id' || str_ends_with($key, '_id') || $key === 'next_after')) {
        return 1;
    }
    if (is_string($v)) {
        return match (true) {
            $key === 'token' => 'TOKEN',
            $key === 'cursor' => '0.0',
            $key === 'order_no' => 'HW-000001',
            $key === 'receipt_no' => 'REC-000001',
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $v) => '2026-01-01T00:00:00+00:00',
            default => $v,
        };
    }

    return $v;
}

it('writes real server responses as contract fixtures for the TypeScript type-check', function () {
    config(['hardwhere.sync.xmin_horizon' => false]);
    $this->seed(DemoSeeder::class);
    $dir = env('CONTRACT_FIXTURES_DIR', base_path('../packages/shared/contract/fixtures'));
    @mkdir($dir, 0777, true);
    $written = [];
    $rec = function (string $name, $response, ?int $status = null) use ($dir, &$written) {
        $status ? $response->assertStatus($status) : $response->assertSuccessful();
        file_put_contents("$dir/$name.json", json_encode(normalizeFixture(json_decode($response->getContent())), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $written[] = $name;
    };
    $photo = fn () => UploadedFile::fake()->image('p.jpg', 600, 600);
    $multipart = ['Accept' => 'application/json'];

    // --- vendor
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'owner.a@hardwhere.test', 'password' => 'password', 'app' => 'vendor', 'tenant' => 'dela-cruz-hardware']);
    $rec('auth.login.vendor', $login);
    $owner = ['Authorization' => 'Bearer '.$login->json('token')];
    $rec('auth.me', $this->getJson('/api/v1/auth/me', $owner));
    $rec('vendor.dashboard', $this->getJson('/api/v1/vendor/dashboard', $owner));
    $rec('vendor.stores', $this->getJson('/api/v1/vendor/stores', $owner));
    $rec('vendor.locations', $this->getJson('/api/v1/vendor/locations', $owner));
    $inv = $this->getJson('/api/v1/vendor/inventory?per_page=3', $owner);
    $rec('vendor.inventory.list', $inv);
    $itemId = $inv->json('data.0.id');
    $rec('vendor.inventory.get', $this->getJson("/api/v1/vendor/inventory/$itemId", $owner));
    $rec('vendor.inventory.ledger', $this->getJson("/api/v1/vendor/inventory/$itemId/ledger", $owner));
    $rec('vendor.listings.list', $this->getJson('/api/v1/vendor/listings?per_page=3', $owner));
    $rec('vendor.catalog.search', $this->getJson('/api/v1/vendor/catalog/search?q=hammer', $owner));
    $rec('vendor.staff', $this->getJson('/api/v1/vendor/staff', $owner));
    $rec('vendor.suppliers', $this->getJson('/api/v1/vendor/suppliers', $owner));
    $rec('vendor.orders.list', $this->getJson('/api/v1/vendor/orders', $owner));
    $rec('vendor.sales.list', $this->getJson('/api/v1/vendor/sales', $owner));

    $qc = collect($this->getJson('/api/v1/vendor/stores', $owner)->json('data'))->firstWhere('code', 'DCH-QC');
    $rec('vendor.pos.search', $this->getJson('/api/v1/vendor/pos/search?store_id='.$qc['id'].'&q=hammer', $owner));
    $listing = $this->getJson('/api/v1/vendor/pos/search?store_id='.$qc['id'].'&q=DCH-NL-3', $owner)->json('data.0');
    $rec('vendor.pos.checkout', $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $qc['id'], 'items' => [['listing_id' => $listing['id'], 'quantity' => 100, 'expected_unit_price_cents' => $listing['price_cents']]],
        'payment' => ['method' => 'cash', 'tendered_cents' => 99999999]], $owner + idem('fx-sale')), 201);
    $rec('vendor.pos.checkoutStatus', $this->getJson('/api/v1/vendor/pos/checkout/test-key-fx-sale', $owner));
    $rec('error.stock_unavailable', $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $qc['id'], 'items' => [['listing_id' => $listing['id'], 'quantity' => 500]], 'payment' => ['method' => 'cash', 'tendered_cents' => 99999999]], $owner + idem('fx-sale2')), 409);
    $rec('error.validation', $this->postJson('/api/v1/vendor/pos/checkout', ['items' => []], $owner + idem('fx-bad')), 422);
    $rec('error.unauthenticated', $this->getJson('/api/v1/vendor/inventory'), 401);

    $staff = World::login($this, 'staff.a@hardwhere.test', 'vendor', 'dela-cruz-hardware');
    $ai = $this->post('/api/v1/vendor/ai/analyses', ['image' => $photo(), 'hint' => 'ambiguous-cordless-drill'], $staff + idem('fx-ai') + $multipart);
    $rec('vendor.ai.analysis', $ai, 201);
    $rec('vendor.ai.confirm', $this->postJson('/api/v1/vendor/ai/analyses/'.$ai->json('data.id').'/confirm', ['decision' => 'rejected'], $staff + idem('fx-ai-confirm')), 201);
    $rec('vendor.pos.photoSearch', $this->post('/api/v1/vendor/pos/photo-search', ['image' => $photo(), 'hint' => 'barcode-visible', 'store_id' => $qc['id']], $owner + idem('fx-pos-photo') + $multipart));

    // --- customer
    $cust = $this->postJson('/api/v1/auth/login', ['email' => 'ana.customer@hardwhere.test', 'password' => 'password', 'app' => 'customer']);
    $rec('auth.login.customer', $cust);
    $c = ['Authorization' => 'Bearer '.$cust->json('token')];
    $rec('public.categories', $this->getJson('/api/v1/public/categories'));
    $search = $this->getJson('/api/v1/public/search?q=hammer');
    $rec('public.search', $search);
    $rec('public.search.filters', $this->getJson('/api/v1/public/search/filters?category=power-tools'));
    $rec('public.search.unlisted', $this->getJson('/api/v1/public/search?q=bosch'));
    $pid = $search->json('data.0.id');
    $rec('public.product', $this->getJson("/api/v1/public/products/$pid"));
    $pl = $this->getJson("/api/v1/public/products/$pid/listings?lat=14.6&lng=121.0");
    $rec('public.product.listings', $pl);
    $rec('public.store', $this->getJson('/api/v1/public/stores/'.$pl->json('data.0.store.id')));
    $rec('public.stores', $this->getJson('/api/v1/public/stores?lat=14.6&lng=121.0'));
    $rec('public.store.listings', $this->getJson('/api/v1/public/stores/'.$pl->json('data.0.store.id').'/listings?per_page=2'));
    $rec('public.listing', $this->getJson('/api/v1/public/listings/'.$pl->json('data.0.id')));
    $rec('customer.photoSearch', $this->post('/api/v1/customer/search/photo', ['image' => $photo(), 'hint' => 'barcode-visible'], $c + idem('fx-cphoto') + $multipart));
    $rec('customer.cart', $this->putJson('/api/v1/customer/cart', ['items' => [['listing_id' => $pl->json('data.0.id'), 'quantity' => 1]]], $c));
    $rec('customer.orders.place', $this->postJson('/api/v1/customer/orders', ['items' => [['listing_id' => $pl->json('data.0.id'), 'quantity' => 1]], 'fulfillment_type' => 'pickup', 'contact_name' => 'Ana', 'contact_phone' => '09171230001'], $c + idem('fx-order')), 201);
    $rec('customer.orders.list', $this->getJson('/api/v1/customer/orders', $c));

    // --- sync
    foreach (['category', 'product', 'store', 'listing', 'inventory_item', 'order'] as $entity) {
        $rec("sync.vendor.snapshot.$entity", $this->getJson("/api/v1/vendor/sync/snapshot?entity=$entity&limit=2", $owner));
    }
    foreach (['store', 'listing', 'product'] as $entity) {
        $rec("sync.public.snapshot.$entity", $this->getJson("/api/v1/public/sync/snapshot?entity=$entity&limit=2"));
    }
    $rec('sync.customer.snapshot.order', $this->getJson('/api/v1/customer/sync/snapshot?entity=order&limit=2', $c));
    $before = $this->getJson('/api/v1/public/sync/changes?cursor=0.0&limit=1')->json('cursor');
    $listingId = $pl->json('data.0.id');
    TenantListing::query()->withoutGlobalScopes()->whereKey($listingId)->update(['visibility' => 'hidden']);
    app(SyncRecorder::class)->listing(TenantListing::query()->withoutGlobalScopes()->find($listingId)->tenant_id, $listingId);
    $rec('sync.public.changes', $this->getJson('/api/v1/public/sync/changes?cursor=0.0&limit=500'));

    expect(count($written))->toBeGreaterThan(40);
    expect(glob("$dir/*.json"))->toHaveCount(count(array_unique($written)));
});
