<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Support\World;

beforeEach(function () {
    $this->refs = World::catalog();
    $this->w = World::tenant('alpha');
    $this->s = World::stocked($this->w, $this->refs['STH16'], 5, 35000);
    $this->h = World::login($this, 'owner@alpha.test', 'vendor', 'alpha');
});

it('returns 401, 403, 404, 405, 409, 422 and 429 in the same JSON envelope', function () {
    $this->getJson('/api/v1/vendor/inventory')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    World::customer('cust@test.test');
    $this->getJson('/api/v1/vendor/inventory', World::login($this, 'cust@test.test', 'customer'))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    $this->getJson('/api/v1/vendor/inventory/999999', $this->h)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->getJson('/api/v1/does/not/exist')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->deleteJson('/api/v1/vendor/inventory', [], $this->h)->assertStatus(405)->assertJsonPath('error.code', 'http_error');
    $this->postJson('/api/v1/vendor/pos/checkout', ['items' => [['listing_id' => 1, 'quantity' => 1]], 'payment' => ['method' => 'cash', 'tendered_cents' => 1]], $this->h)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');
    $r = $this->postJson('/api/v1/vendor/pos/checkout', ['items' => [], 'payment' => ['method' => 'bitcoin']], $this->h + idem())->assertStatus(422);
    expect($r->json('error.code'))->toBe('validation_failed')->and($r->json('error.fields'))->toHaveKeys(['items', 'payment.method']);
    $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $this->w['store']->id, 'items' => [['listing_id' => $this->s['listing']->id, 'quantity' => 50]], 'payment' => ['method' => 'cash', 'tendered_cents' => 9e9]], $this->h + idem())
        ->assertStatus(409)->assertJsonPath('error.code', 'stock_unavailable')->assertJsonStructure(['error' => ['details' => ['available', 'requested']]]);
});

it('never leaks internals on server errors', function () {
    config(['app.debug' => true]);
    Route::get('/api/v1/boom', fn () => throw new RuntimeException('secret db password in message'));
    $r = $this->getJson('/api/v1/boom', $this->h);
    expect($r->status())->toBe(500);
    $body = $r->getContent();
    // The envelope for unexpected errors is the framework's JSON; production (debug=false) hides details.
    config(['app.debug' => false]);
    $prod = $this->getJson('/api/v1/boom', $this->h);
    expect($prod->status())->toBe(500)->and($prod->getContent())->not->toContain('secret db password')->not->toContain('RuntimeException');
});

it('paginates with a stable envelope and clamps page size', function () {
    foreach (['TLPL8', 'NAIL3', 'TEK100'] as $ref) {
        World::stocked($this->w, $this->refs[$ref], 5, 10000);
    }
    $r = $this->getJson('/api/v1/vendor/inventory?per_page=2&page=2', $this->h)->assertOk();
    expect($r->json())->toHaveKeys(['data', 'links', 'meta'])->and($r->json('meta.per_page'))->toBe(2)->and($r->json('meta.current_page'))->toBe(2)->and($r->json('meta.total'))->toBe(4)->and($r->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/v1/vendor/inventory?per_page=9999', $this->h)->json('meta.per_page'))->toBe(100);
    expect(collect($this->getJson('/api/v1/vendor/inventory?q=hammer', $this->h)->json('data'))->pluck('sku')->all())->toBe([$this->s['item']->tenant_sku]);
    expect($this->getJson('/api/v1/vendor/inventory?low_stock=1', $this->h)->json('data'))->toHaveCount(0);
});

it('serves a health endpoint and reports AI mode to admins', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok');
    User::query()->forceCreate(['name' => 'A', 'email' => 'admin@test.test', 'password' => bcrypt('password'), 'is_platform_admin' => true]);
    $h = World::login($this, 'admin@test.test', 'admin');
    $this->getJson('/api/v1/admin/health', $h)->assertOk()->assertJsonPath('data.ai_provider', 'mock')->assertJsonPath('data.ai_live', false);
    $this->postJson('/api/v1/admin/tenants', ['name' => 'New Co', 'slug' => 'new-co', 'owner_email' => 'o@new.test', 'owner_name' => 'O', 'owner_password' => 'longenough123'], $h)->assertCreated();
    $this->postJson('/api/v1/auth/login', ['email' => 'o@new.test', 'password' => 'longenough123', 'app' => 'vendor'], [])->assertOk()->assertJsonPath('role', 'owner');
    $id = Tenant::where('slug', 'new-co')->value('id');
    $this->patchJson("/api/v1/admin/tenants/$id/status", ['status' => 'suspended'], $h)->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => 'o@new.test', 'password' => 'longenough123', 'app' => 'vendor'])->assertForbidden();
    $this->getJson('/api/v1/admin/tenants', $h)->assertOk()->assertJsonStructure(['data', 'meta']);
});
