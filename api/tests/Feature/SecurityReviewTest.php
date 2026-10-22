<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Enums\TenantRole;
use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\PersonalAccessToken;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Reservation;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TenantListing;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\ChangeFeed;
use App\Services\InventoryService;
use App\Services\ReservationService;
use App\Services\StockService;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\World;

/** Regression tests for the independent security review. Each test names the finding it pins down. */
beforeEach(function () {
    $this->refs = World::catalog();
    $this->a = World::tenant('alpha');
    $this->b = World::tenant('bravo');
    $this->la = World::stocked($this->a, $this->refs['STH16'], 10, 35000);
    $this->lb = World::stocked($this->b, $this->refs['STH16'], 10, 32900);
});

it('[critical] list endpoints with a search term never return another tenant\'s rows', function () {
    $ownerA = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    foreach (['/api/v1/vendor/listings?q=hammer', '/api/v1/vendor/listings?q=a', '/api/v1/vendor/listings?q=stanley&status=draft',
        '/api/v1/vendor/inventory?q=hammer', '/api/v1/vendor/pos/search?store_id='.$this->a['store']->id.'&q=hammer'] as $url) {
        $ids = collect($this->getJson($url, $ownerA)->assertOk()->json('data'))->pluck('id')->all();
        expect(array_diff($ids, [$this->la['listing']->id, $this->la['item']->id]))->toBe([], "leak via $url");
        expect(json_encode($ids))->not->toContain((string) $this->lb['listing']->id.',');
    }
    $ids = collect($this->getJson('/api/v1/vendor/listings?q=hammer', $ownerA)->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$this->la['listing']->id]);
    // and the bound-tenant backstop holds even when a developer passes the wrong tenant to forTenant()
    TenantContext::bind(World::ctx($this->a['tenant'], $this->a['owner'], TenantRole::Owner));
    expect(TenantListing::query()->forTenant($this->b['tenant']->id)->count())->toBe(0);
    TenantContext::bind(null);
});

it('[high] a demoted platform admin loses admin access immediately, including renewal', function () {
    $admin = User::query()->forceCreate(['name' => 'A', 'email' => 'admin@test.test', 'password' => bcrypt('password'), 'is_platform_admin' => true, 'email_verified_at' => now()]);
    $h = World::login($this, 'admin@test.test', 'admin');
    $this->getJson('/api/v1/admin/health', $h)->assertOk();
    $admin->forceFill(['is_platform_admin' => false])->save();
    $this->getJson('/api/v1/admin/health', $h)->assertForbidden();
    expect($this->postJson('/api/v1/auth/renew', [], $h)->status())->toBeIn([401, 403]); // the first rejected call already revoked the token
});

it('[high] an owner cannot attach a self-registered (unverified) account as staff', function () {
    $this->postJson('/api/v1/auth/register', ['name' => 'Future Hire', 'email' => 'future@shop.test', 'password' => 'long-enough-pass-1'])->assertCreated();
    $owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $this->postJson('/api/v1/vendor/staff', ['email' => 'future@shop.test', 'role' => 'cashier'], $owner)->assertStatus(422)->assertJsonPath('error.code', 'user_unverified');
    // accounts the owner creates are trusted (the owner supplied and vouches for the address); verified existing accounts can be attached
    $this->postJson('/api/v1/vendor/staff', ['email' => 'new.hire@shop.test', 'name' => 'New Hire', 'password' => 'long-enough-pass-1', 'role' => 'cashier'], $owner)->assertCreated();
    expect(User::where('email', 'new.hire@shop.test')->value('email_verified_at'))->not->toBeNull();
    World::member($this->b['tenant'], TenantRole::Staff, 'verified@shop.test');
    $this->postJson('/api/v1/vendor/staff', ['email' => 'verified@shop.test', 'role' => 'staff'], $owner)->assertCreated();
});

it('[high] resetting a password revokes every API token', function () {
    $user = $this->a['owner'];
    World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    expect(PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(1);
    app(ResetUserPassword::class)->reset($user, ['password' => 'A-new-Passw0rd!2026', 'password_confirmation' => 'A-new-Passw0rd!2026']);
    expect(PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(0);
});

it('[high] web password change also revokes API tokens', function () {
    $user = World::customer('pw@test.test');
    $user->createToken('device', ['customer']);
    $this->actingAs($user)->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'A-new-Passw0rd!2026', 'password_confirmation' => 'A-new-Passw0rd!2026']);
    expect($user->tokens()->count())->toBe(0);
});

it('[high] rejects oversized pixel dimensions before decoding the image', function () {
    $crc = fn (string $d) => pack('N', crc32($d));
    $ihdr = pack('NN', 9000, 9000)."\x08\x02\x00\x00\x00";
    $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.$crc('IHDR'.$ihdr).pack('N', 0).'IEND'.$crc('IEND');
    $path = tempnam(sys_get_temp_dir(), 'bomb').'.png';
    file_put_contents($path, $png);
    $h = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $before = memory_get_peak_usage(true);
    $this->post('/api/v1/vendor/ai/analyses', ['image' => new UploadedFile($path, 'bomb.png', 'image/png', null, true)], $h + idem() + ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_image_size');
    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(40 * 1024 * 1024);
});

describe('POS sessions', function () {
    beforeEach(function () {
        $this->s2 = Store::create(['tenant_id' => $this->a['tenant']->id, 'name' => 'Branch', 'code' => 'BR-9', 'address_line' => 'x', 'city' => 'y', 'province' => 'z']);
        $loc2 = InventoryLocation::create(['tenant_id' => $this->a['tenant']->id, 'store_id' => $this->s2->id, 'name' => 'Main', 'code' => 'MAIN']);
        $this->reg2 = PosRegister::create(['tenant_id' => $this->a['tenant']->id, 'store_id' => $this->s2->id, 'name' => 'Counter 2']);
        World::member($this->a['tenant'], TenantRole::Cashier, 'c1@alpha.test', $this->a['store']);
        World::member($this->a['tenant'], TenantRole::Cashier, 'c2@alpha.test', $this->s2);
        $this->c1 = World::login($this, 'c1@alpha.test', 'vendor', 'alpha');
        $this->c2 = World::login($this, 'c2@alpha.test', 'vendor', 'alpha');
        $this->session1 = $this->postJson('/api/v1/vendor/pos/sessions', ['register_id' => $this->a['register']->id, 'opening_float_cents' => 1000], $this->c1)->assertCreated()->json('data.id');
        $this->loc2 = $loc2;
    });

    it('[medium] a cashier cannot close another store\'s session', function () {
        $this->postJson("/api/v1/vendor/pos/sessions/{$this->session1}/close", ['closing_cash_cents' => 1], $this->c2)->assertForbidden();
        expect(PosSession::find($this->session1)->closed_at)->toBeNull();
        $this->postJson("/api/v1/vendor/pos/sessions/{$this->session1}/close", ['closing_cash_cents' => 1000], $this->c1)->assertOk();
    });

    it('[medium] a sale cannot be attributed to a session of another store', function () {
        app(InventoryService::class)->createItem($this->a['ctx'], $this->refs['NAIL3'], ['tenant_sku' => 'NAIL-B2', 'cost_cents' => 100, 'price_cents' => 1000, 'quantity' => 5, 'store_id' => $this->s2->id, 'location_id' => $this->loc2->id]);
        $listing = TenantListing::where('tenant_sku', 'NAIL-B2')->first();
        $this->postJson('/api/v1/vendor/pos/checkout', ['items' => [['listing_id' => $listing->id, 'quantity' => 1]], 'payment' => ['method' => 'cash', 'tendered_cents' => 5000], 'pos_session_id' => $this->session1], $this->c2 + idem())
            ->assertStatus(422)->assertJsonPath('error.code', 'session_invalid');
    });
});

it('[medium] two cashiers reusing one idempotency key each get their own sale', function () {
    World::member($this->a['tenant'], TenantRole::Cashier, 'c1@alpha.test', $this->a['store']);
    World::member($this->a['tenant'], TenantRole::Cashier, 'c2@alpha.test', $this->a['store']);
    $c1 = World::login($this, 'c1@alpha.test', 'vendor', 'alpha');
    $c2 = World::login($this, 'c2@alpha.test', 'vendor', 'alpha');
    $body = ['store_id' => $this->a['store']->id, 'items' => [['listing_id' => $this->la['listing']->id, 'quantity' => 1]], 'payment' => ['method' => 'cash', 'tendered_cents' => 99999]];
    $one = $this->postJson('/api/v1/vendor/pos/checkout', $body, $c1 + ['Idempotency-Key' => 'shared-key-0001'])->assertCreated();
    $two = $this->postJson('/api/v1/vendor/pos/checkout', $body, $c2 + ['Idempotency-Key' => 'shared-key-0001'])->assertCreated();
    expect($two->json('data.id'))->not->toBe($one->json('data.id'))->and($two->json('data.cashier_id'))->not->toBe($one->json('data.cashier_id'));
    expect(DB::table('stock_snapshots')->where('inventory_item_id', $this->la['item']->id)->value('on_hand'))->toBe(8);
});

it('[medium] cross-tenant references from request bodies are 422/404, never a 500', function () {
    $owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $foreignStore = $this->b['store']->id;
    $this->postJson('/api/v1/vendor/staff', ['email' => 'x@shop.test', 'name' => 'X', 'password' => 'long-enough-pass-1', 'role' => 'cashier', 'store_id' => $foreignStore], $owner)->assertStatus(422);
    $mid = TenantMembership::where('tenant_id', $this->a['tenant']->id)->value('id');
    World::member($this->a['tenant'], TenantRole::Cashier, 'victim@alpha.test');
    $victim = TenantMembership::whereHas('user', fn ($q) => $q->where('email', 'victim@alpha.test'))->value('id');
    $this->putJson("/api/v1/vendor/staff/$victim", ['role' => 'cashier', 'store_id' => $foreignStore], $owner)->assertStatus(422);
    $foreignSupplier = Supplier::create(['tenant_id' => $this->b['tenant']->id, 'name' => 'Other co']);
    $r = $this->postJson('/api/v1/vendor/inventory', ['variant_id' => $this->refs['NAIL3'], 'tenant_sku' => 'N-1', 'cost_cents' => 1, 'price_cents' => 5, 'quantity' => 1, 'store_id' => $this->a['store']->id, 'supplier_id' => $foreignSupplier->id], $owner);
    expect($r->status())->toBeIn([404, 422]);
});

it('[medium] store-bound staff only see their own store\'s stock and ledger', function () {
    $branch = Store::create(['tenant_id' => $this->a['tenant']->id, 'name' => 'Branch', 'code' => 'BR-8', 'address_line' => 'x', 'city' => 'y', 'province' => 'z']);
    $loc2 = InventoryLocation::create(['tenant_id' => $this->a['tenant']->id, 'store_id' => $branch->id, 'name' => 'Main', 'code' => 'MAIN']);
    DB::transaction(fn () => app(StockService::class)->adjust($this->a['tenant']->id, $this->a['owner']->id, $this->la['item']->id, $loc2->id, 'purchase_receipt', 7));
    World::member($this->a['tenant'], TenantRole::StoreManager, 'mgr@alpha.test', $this->a['store']);
    $mgr = World::login($this, 'mgr@alpha.test', 'vendor', 'alpha');
    $item = $this->getJson('/api/v1/vendor/inventory/'.$this->la['item']->id, $mgr)->assertOk()->json('data');
    expect($item['on_hand'])->toBe(10)->and(collect($item['locations'])->pluck('location_id')->all())->toBe([$this->a['location']->id]);
    $ledger = collect($this->getJson('/api/v1/vendor/inventory/'.$this->la['item']->id.'/ledger', $mgr)->json('data'))->pluck('location_id')->unique()->all();
    expect($ledger)->toBe([$this->a['location']->id]);
});

it('[medium] suspending a tenant tells every device to drop its listings and stores', function () {
    config(['hardwhere.sync.xmin_horizon' => false]);
    World::customer('cust@test.test');
    $head = app(ChangeFeed::class)->head(['catalog', 'public']);
    User::query()->forceCreate(['name' => 'A', 'email' => 'admin@test.test', 'password' => bcrypt('password'), 'is_platform_admin' => true, 'email_verified_at' => now()]);
    $admin = World::login($this, 'admin@test.test', 'admin');
    $this->patchJson('/api/v1/admin/tenants/'.$this->a['tenant']->id.'/status', ['status' => 'suspended'], $admin)->assertOk();
    $ops = collect($this->getJson("/api/v1/public/sync/changes?cursor=$head")->json('changes'));
    expect($ops->where('entity', 'listing')->where('id', $this->la['listing']->id)->first()['op'])->toBe('delete')
        ->and($ops->where('entity', 'store')->where('id', $this->a['store']->id)->first()['op'])->toBe('delete');
});

it('[medium] public sync pages are capped and a tampered limit cannot amplify work', function () {
    $r = $this->getJson('/api/v1/public/sync/changes?cursor=0.0&limit=500')->assertOk();
    expect(count($r->json('changes')))->toBeLessThanOrEqual(100);
});

it('[low] emails are case-insensitive and AI replays do not call the provider twice', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'OWNER@Alpha.TEST', 'password' => 'password', 'app' => 'vendor'])->assertOk();
    $this->postJson('/api/v1/auth/register', ['name' => 'N', 'email' => 'Mixed@Case.test', 'password' => 'long-enough-pass-1'])->assertCreated();
    $this->postJson('/api/v1/auth/register', ['name' => 'N', 'email' => 'mixed@case.test', 'password' => 'long-enough-pass-1'])->assertStatus(422);

    config(['hardwhere.ai.provider' => 'gemma', 'hardwhere.ai.gemma.api_key' => 'k']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"name":"Hammer","brand":"Stanley","model":"51-188","overall_confidence":0.9}']]]]]])]);
    $h = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $file = UploadedFile::fake()->image('p.jpg', 400, 400);
    $post = fn () => $this->post('/api/v1/vendor/ai/analyses', ['image' => $file], $h + ['Idempotency-Key' => 'ai-replay-0001', 'Accept' => 'application/json']);
    $post()->assertCreated();
    $post()->assertCreated();
    Http::assertSentCount(1);
});

it('[low] bad catalog barcodes are a validation error, not a 500', function () {
    $owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $cat = Category::first()->id;
    $this->postJson('/api/v1/vendor/catalog/candidates', ['category_id' => $cat, 'name' => 'X', 'variants' => [['name' => 'X', 'barcodes' => [str_repeat('9', 40) => 'EAN13']]]], $owner)->assertStatus(422);
    $this->postJson('/api/v1/vendor/catalog/candidates', ['category_id' => $cat, 'name' => 'X', 'variants' => [['name' => 'X', 'barcodes' => ['not-a-code' => 'EAN13']]]], $owner)->assertStatus(422);
});

it('[low] overdue preparing orders do not starve the expiry sweep', function () {
    $cust = World::customer('c@test.test');
    $h = World::login($this, 'c@test.test', 'customer');
    $o = fn () => $this->postJson('/api/v1/customer/orders', ['items' => [['listing_id' => $this->la['listing']->id, 'quantity' => 1]], 'fulfillment_type' => 'pickup', 'contact_name' => 'C', 'contact_phone' => '09171234567'], $h + idem())->assertCreated()->json('data.id');
    $owner = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
    $stuck = $o();
    foreach (['confirmed', 'preparing'] as $to) {
        $this->postJson("/api/v1/vendor/orders/$stuck/transition", ['to' => $to], $owner)->assertOk();
    }
    $expiring = $o();
    Reservation::query()->withoutGlobalScopes()->whereIn('order_id', [$stuck, $expiring])->update(['expires_at' => now()->subHour()]);
    expect(app(ReservationService::class)->expireDue(1))->toBe(1); // limit 1: must pick the expirable one, not the stuck one
    expect(Order::query()->withoutGlobalScopes()->find($expiring)->status)->toBe('expired');
});
