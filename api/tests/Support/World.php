<?php

namespace Tests\Support;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\PosRegister;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantListing;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Tenancy\TenantContext;
use Database\Seeders\CatalogSeeder;

/** Small builders so each test owns exactly the data it needs (the full demo seed is only used by scenario/integrity tests). */
final class World
{
    public static function catalog(): array
    {
        app(CatalogSeeder::class)->seedCatalog(app(CatalogService::class), coreOnly: true);

        return CatalogSeeder::$refs;
    }

    /** @return array{tenant: Tenant, store: Store, location: InventoryLocation, owner: User, ctx: TenantContext, register: PosRegister} */
    public static function tenant(string $slug = 'acme', array $storeAttrs = []): array
    {
        $tenant = Tenant::create(['name' => ucfirst($slug).' Hardware', 'slug' => $slug]);
        $store = Store::create($storeAttrs + ['tenant_id' => $tenant->id, 'name' => 'Main', 'code' => strtoupper(substr($slug, 0, 3)).'-1', 'address_line' => '1 Test St', 'city' => 'Quezon City',
            'province' => 'Metro Manila', 'lat' => 14.65, 'lng' => 121.03, 'phone' => '+6328000000', 'supports_pickup' => true, 'supports_delivery' => true]);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Main floor', 'code' => 'MAIN']);
        $owner = self::member($tenant, TenantRole::Owner, "owner@$slug.test");
        $register = PosRegister::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Counter 1']);

        return ['tenant' => $tenant, 'store' => $store, 'location' => $location, 'owner' => $owner, 'ctx' => self::ctx($tenant, $owner, TenantRole::Owner), 'register' => $register];
    }

    public static function member(Tenant $tenant, TenantRole $role, string $email, ?Store $store = null): User
    {
        $user = User::query()->where('email', $email)->first() ?? User::query()->forceCreate(['name' => ucfirst($role->value), 'email' => $email, 'password' => bcrypt('password'), 'email_verified_at' => now()]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role->value, 'store_id' => $store?->id]);

        return $user;
    }

    public static function customer(string $email = 'cust@test.test'): User
    {
        return User::query()->forceCreate(['name' => 'Customer', 'email' => $email, 'password' => bcrypt('password'), 'phone' => '+639170000000', 'email_verified_at' => now()]);
    }

    public static function ctx(Tenant $tenant, User $user, TenantRole $role, ?int $storeId = null): TenantContext
    {
        return new TenantContext($tenant->id, $user->id, $role, $storeId);
    }

    /** Stock a canonical variant for a tenant and (optionally) publish it. @return array{item: InventoryItem, listing: TenantListing} */
    public static function stocked(array $w, int $variantId, int $qty, int $priceCents = 100000, bool $publish = true, ?string $sku = null, array $listing = []): array
    {
        $sku ??= 'SKU-'.$variantId.'-'.$w['tenant']->id;
        $item = app(InventoryService::class)->createItem($w['ctx'], $variantId, ['tenant_sku' => $sku, 'cost_cents' => (int) ($priceCents * 0.6), 'price_cents' => $priceCents,
            'quantity' => $qty, 'reorder_threshold' => 2, 'store_id' => $w['store']->id, 'source' => 'manual']);
        $l = TenantListing::query()->where('inventory_item_id', $item->id)->firstOrFail();
        if ($publish) {
            $l = app(InventoryService::class)->updateListing($w['ctx'], $l->id, ['publish' => true, 'pickup_available' => true, 'delivery_available' => true] + $listing);
        }

        return ['item' => $item, 'listing' => $l];
    }

    public static function variant(string $ref): ProductVariant
    {
        return ProductVariant::findOrFail(CatalogSeeder::$refs[$ref]);
    }

    /** Sign in through the real endpoint and return Authorization headers. @return array<string, string> */
    public static function login($test, string $email, string $app = 'vendor', ?string $tenant = null): array
    {
        $r = $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password', 'app' => $app, 'tenant' => $tenant])->assertOk();

        return ['Authorization' => 'Bearer '.$r->json('token')];
    }
}
