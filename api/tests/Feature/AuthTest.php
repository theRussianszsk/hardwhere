<?php

use App\Enums\TenantRole;
use App\Models\PersonalAccessToken;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::tenant('alpha');
    World::customer('cust@test.test');
});

it('signs vendors in with the tenant bound to the token and returns role permissions', function () {
    $r = $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password', 'app' => 'vendor'])->assertOk();
    expect($r->json('tenant.slug'))->toBe('alpha')->and($r->json('role'))->toBe('owner')->and($r->json('permissions'))->toContain('inventory.adjust')
        ->and($r->json('expires_at'))->not->toBeEmpty();
    expect(PersonalAccessToken::query()->first()->tenant_id)->toBe($this->w['tenant']->id);
});

it('asks which business to use when an account belongs to several', function () {
    $other = World::tenant('beta');
    World::member($other['tenant'], TenantRole::Cashier, 'owner@alpha.test');
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password', 'app' => 'vendor'])
        ->assertStatus(422)->assertJsonPath('error.code', 'tenant_required')->assertJsonCount(2, 'error.details.tenants');
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password', 'app' => 'vendor', 'tenant' => 'beta'])->assertOk()->assertJsonPath('role', 'cashier');
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password', 'app' => 'vendor', 'tenant' => 'nope'])->assertForbidden();
});

it('gives the same error for wrong password and unknown email, and rate limits attempts', function () {
    $a = $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'bad', 'app' => 'vendor'])->assertUnauthorized();
    $b = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@alpha.test', 'password' => 'bad', 'app' => 'vendor'])->assertUnauthorized();
    expect($a->json('error'))->toBe($b->json('error'));
    $this->postJson('/api/v1/auth/login', ['email' => 'cust@test.test', 'password' => 'password', 'app' => 'admin'])->assertForbidden();

    RateLimiter::clear('login');
    foreach (range(1, 9) as $i) {
        $last = $this->postJson('/api/v1/auth/login', ['email' => 'spam@x.test', 'password' => 'bad', 'app' => 'vendor']);
    }
    $last->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('expires tokens server-side and requires re-authentication afterwards (there is no refresh token)', function () {
    $h = World::login($this, 'owner@alpha.test');
    $this->getJson('/api/v1/auth/me', $h)->assertOk();
    $this->travel(config('hardwhere.tokens.vendor_ttl_minutes') + 1)->minutes();
    $this->getJson('/api/v1/auth/me', $h)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('rotates a valid token on renew and revokes the old one; logout revokes', function () {
    $r = $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password', 'app' => 'vendor'])->json();
    $old = ['Authorization' => 'Bearer '.$r['token']];
    $new = $this->postJson('/api/v1/auth/renew', [], $old)->assertOk()->json();
    expect($new['token'])->not->toBe($r['token'])->and($new['tenant']['slug'])->toBe('alpha');
    $this->getJson('/api/v1/auth/me', $old)->assertUnauthorized();
    $newH = ['Authorization' => 'Bearer '.$new['token']];
    $this->getJson('/api/v1/auth/me', $newH)->assertOk();
    $this->postJson('/api/v1/auth/logout', [], $newH)->assertOk();
    $this->getJson('/api/v1/auth/me', $newH)->assertUnauthorized();
});

it('cuts off removed staff immediately and revokes their devices', function () {
    $cashier = World::member($this->w['tenant'], TenantRole::Cashier, 'c@alpha.test');
    $h = World::login($this, 'c@alpha.test');
    $owner = World::login($this, 'owner@alpha.test');
    $this->getJson('/api/v1/vendor/inventory', $h)->assertOk();

    $mid = TenantMembership::query()->where('user_id', $cashier->id)->value('id');
    $this->deleteJson("/api/v1/vendor/staff/$mid", [], $owner)->assertOk();
    $this->getJson('/api/v1/vendor/inventory', $h)->assertUnauthorized();
    expect(PersonalAccessToken::query()->where('tokenable_id', $cashier->id)->count())->toBe(0);
    $this->postJson('/api/v1/auth/login', ['email' => 'c@alpha.test', 'password' => 'password', 'app' => 'vendor'])->assertForbidden()->assertJsonPath('error.code', 'no_business_access');
});

it('locks out a suspended tenant and cannot remove or demote the owner', function () {
    $owner = World::login($this, 'owner@alpha.test');
    $ownerMembership = TenantMembership::query()->where('user_id', $this->w['owner']->id)->value('id');
    $this->deleteJson("/api/v1/vendor/staff/$ownerMembership", [], $owner)->assertForbidden();
    $this->putJson("/api/v1/vendor/staff/$ownerMembership", ['role' => 'cashier'], $owner)->assertForbidden();

    $this->w['tenant']->update(['status' => 'suspended']);
    $this->getJson('/api/v1/vendor/inventory', $owner)->assertForbidden()->assertJsonPath('error.code', 'membership_revoked');
});

it('registers customers with strong-password rules and lets only platform admins use admin routes', function () {
    $this->postJson('/api/v1/auth/register', ['name' => 'N', 'email' => 'new@test.test', 'password' => 'password'])->assertCreated()->assertJsonPath('app', 'customer');
    $this->postJson('/api/v1/auth/register', ['name' => 'N', 'email' => 'new@test.test', 'password' => 'password'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    $admin = User::query()->forceCreate(['name' => 'Admin', 'email' => 'admin@test.test', 'password' => bcrypt('password'), 'is_platform_admin' => true]);
    $h = World::login($this, 'admin@test.test', 'admin');
    $this->getJson('/api/v1/admin/health', $h)->assertOk()->assertJsonPath('data.database', 'ok');
    $this->getJson('/api/v1/admin/health', World::login($this, 'owner@alpha.test'))->assertForbidden();
    // admins cannot read tenant inventory with an admin token
    $this->getJson('/api/v1/vendor/inventory', $h)->assertForbidden();
});
