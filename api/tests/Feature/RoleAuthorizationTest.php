<?php

use App\Enums\TenantRole;
use Tests\Support\World;

beforeEach(function () {
    $this->refs = World::catalog();
    $this->w = World::tenant('alpha');
    $this->other = World::tenant('other-store-tenant'); // unrelated; keeps ids distinct
    $this->s = World::stocked($this->w, $this->refs['STH16'], 10, 35000);
    foreach (TenantRole::cases() as $role) {
        if ($role !== TenantRole::Owner) {
            World::member($this->w['tenant'], $role, $role->value.'@alpha.test');
        }
    }
});

dataset('roleMatrix', function () {
    // [method, uri, body, roles allowed (anything else must get 403)]
    $all = ['owner', 'store_manager', 'inventory_manager', 'cashier', 'marketplace_manager', 'staff'];

    return [
        'dashboard' => ['GET', '/api/v1/vendor/dashboard', [], $all],
        'staff list' => ['GET', '/api/v1/vendor/staff', [], ['owner']],
        'create store' => ['POST', '/api/v1/vendor/stores', ['name' => 'X', 'code' => 'X1', 'address_line' => 'a', 'city' => 'b', 'province' => 'c'], ['owner']],
        'suppliers (cost data)' => ['GET', '/api/v1/vendor/suppliers', [], ['owner', 'store_manager', 'inventory_manager']],
        'ledger' => ['GET', '/api/v1/vendor/inventory/{item}/ledger', [], ['owner', 'store_manager', 'inventory_manager']],
        'stock adjustment' => ['POST', '/api/v1/vendor/inventory/{item}/adjustments', ['location_id' => '{loc}', 'movement_type' => 'manual_adjustment', 'quantity_delta' => 1, 'note' => 'x'], ['owner', 'store_manager', 'inventory_manager']],
        'update listing' => ['PATCH', '/api/v1/vendor/listings/{listing}', ['expected_row_version' => 99, 'price_cents' => 5000], ['owner', 'store_manager', 'marketplace_manager']],
        'orders list' => ['GET', '/api/v1/vendor/orders', [], ['owner', 'store_manager', 'marketplace_manager']],
        'pos search' => ['GET', '/api/v1/vendor/pos/search?store_id={store}', [], ['owner', 'store_manager', 'cashier']],
        'sales history' => ['GET', '/api/v1/vendor/sales', [], ['owner', 'store_manager', 'cashier']],
        'void sale' => ['POST', '/api/v1/vendor/sales/999999/void', ['reason' => 'x'], ['owner', 'store_manager']],
        'ai analyze' => ['GET', '/api/v1/vendor/ai/analyses/999999', [], ['owner', 'store_manager', 'inventory_manager', 'staff']],
        'create inventory' => ['POST', '/api/v1/vendor/inventory', ['variant_id' => 1], ['owner', 'store_manager', 'inventory_manager', 'staff']],
    ];
});

it('enforces the role matrix on the server', function (string $method, string $uri, array $body, array $allowed) {
    $sub = ['{item}' => $this->s['item']->id, '{listing}' => $this->s['listing']->id, '{store}' => $this->w['store']->id, '{loc}' => $this->w['location']->id];
    $uri = strtr($uri, $sub);
    $body = json_decode(strtr(json_encode($body), array_map('strval', ['"{loc}"' => $this->w['location']->id])), true);

    foreach (TenantRole::cases() as $role) {
        $email = $role === TenantRole::Owner ? 'owner@alpha.test' : $role->value.'@alpha.test';
        $headers = World::login($this, $email, 'vendor', 'alpha') + idem();
        $status = $this->json($method, $uri, $body, $headers)->status();

        in_array($role->value, $allowed, true)
            ? expect($status)->not->toBe(403, "{$role->value} should be allowed on $method $uri")
            : expect($status)->toBe(403, "{$role->value} must be forbidden on $method $uri");
    }
})->with('roleMatrix');

it('lets only discount-capable roles apply discounts, and keeps store-bound staff in their store', function () {
    $second = \App\Models\Store::create(['tenant_id' => $this->w['tenant']->id, 'name' => 'Branch', 'code' => 'BR-2', 'address_line' => 'x', 'city' => 'y', 'province' => 'z']);
    World::member($this->w['tenant'], TenantRole::Cashier, 'bound@alpha.test', $this->w['store']);
    $cashier = World::login($this, 'bound@alpha.test', 'vendor', 'alpha');
    $line = ['listing_id' => $this->s['listing']->id, 'quantity' => 1];
    $pay = ['method' => 'cash', 'tendered_cents' => 99999];

    $this->postJson('/api/v1/vendor/pos/checkout', ['items' => [$line + ['discount_cents' => 500]], 'payment' => $pay], $cashier + idem())->assertForbidden();
    $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $second->id, 'items' => [$line], 'payment' => $pay], $cashier + idem())->assertForbidden();
    $this->postJson('/api/v1/vendor/pos/checkout', ['items' => [$line], 'payment' => $pay], $cashier + idem())->assertCreated(); // store inferred from membership

    $mgr = World::login($this, 'store_manager@alpha.test', 'vendor', 'alpha');
    $this->postJson('/api/v1/vendor/pos/checkout', ['store_id' => $this->w['store']->id, 'items' => [$line + ['discount_cents' => 500]], 'payment' => $pay], $mgr + idem())
        ->assertCreated()->assertJsonPath('data.discount_cents', 500)->assertJsonPath('data.total_cents', 34500);
});
