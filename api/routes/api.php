<?php

use App\Http\Controllers\Api\Admin\PlatformController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Customer\CartController;
use App\Http\Controllers\Api\Customer\MarketplaceController;
use App\Http\Controllers\Api\Customer\OrderController as CustomerOrders;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\Vendor\AiController;
use App\Http\Controllers\Api\Vendor\InventoryController;
use App\Http\Controllers\Api\Vendor\ListingController;
use App\Http\Controllers\Api\Vendor\OrderController as VendorOrders;
use App\Http\Controllers\Api\Vendor\PosController;
use App\Http\Controllers\Api\Vendor\TenantAdminController;
use Illuminate\Support\Facades\Route;

/*
| Versioned JSON API (/api/v1). Tenant context is NEVER taken from the request: ResolveTenant derives it from the access token.
| Permission middleware (can:*) maps to App\Enums\TenantRole. The mobile TypeScript clients mirror this file via
| packages/shared/contract/endpoints.json, which a PHPUnit contract test checks against the router.
*/
Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]));

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/renew', [AuthController::class, 'renew']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
    });

    // Anonymous browsing: canonical catalog first, then public tenant listings.
    Route::prefix('public')->middleware('throttle:public')->group(function () {
        Route::get('categories', [MarketplaceController::class, 'categories']);
        Route::get('search', [MarketplaceController::class, 'search']);
        Route::get('search/filters', [MarketplaceController::class, 'searchFilters']);
        Route::get('products/{product}', [MarketplaceController::class, 'product']);
        Route::get('products/{product}/listings', [MarketplaceController::class, 'listings']);
        Route::get('listings/{listing}', [MarketplaceController::class, 'listing']);
        Route::get('stores', [MarketplaceController::class, 'stores']);
        Route::get('stores/{store}', [MarketplaceController::class, 'store']);
        Route::get('stores/{store}/listings', [MarketplaceController::class, 'storeListings']);
        Route::get('sync/snapshot', [SyncController::class, 'snapshot'])->defaults('kind', 'public');
        Route::get('sync/changes', [SyncController::class, 'changes'])->defaults('kind', 'public');
    });

    Route::prefix('customer')->middleware(['auth:sanctum', 'abilities:customer', 'throttle:api'])->group(function () {
        Route::post('search/photo', [MarketplaceController::class, 'photoSearch'])->middleware('throttle:photo');
        Route::get('cart', [CartController::class, 'show']);
        Route::put('cart', [CartController::class, 'replace']);
        Route::delete('cart', [CartController::class, 'clear']);
        Route::get('orders', [CustomerOrders::class, 'index']);
        Route::post('orders', [CustomerOrders::class, 'store']);
        Route::get('orders/{order}', [CustomerOrders::class, 'show']);
        Route::post('orders/{order}/cancel', [CustomerOrders::class, 'cancel']);
        Route::get('sync/snapshot', [SyncController::class, 'snapshot'])->defaults('kind', 'customer');
        Route::get('sync/changes', [SyncController::class, 'changes'])->defaults('kind', 'customer');
    });

    Route::prefix('vendor')->middleware(['auth:sanctum', 'abilities:vendor', 'tenant', 'throttle:api'])->group(function () {
        Route::get('dashboard', [TenantAdminController::class, 'dashboard'])->middleware('can:inventory.view');
        Route::get('stores', [TenantAdminController::class, 'stores']);
        Route::get('locations', [TenantAdminController::class, 'locations']);
        Route::post('stores', [TenantAdminController::class, 'createStore'])->middleware('can:stores.manage');
        Route::put('stores/{store}', [TenantAdminController::class, 'updateStore'])->middleware('can:stores.manage');
        Route::get('staff', [TenantAdminController::class, 'staff'])->middleware('can:staff.manage');
        Route::post('staff', [TenantAdminController::class, 'addStaff'])->middleware('can:staff.manage');
        Route::put('staff/{membership}', [TenantAdminController::class, 'updateStaff'])->middleware('can:staff.manage');
        Route::delete('staff/{membership}', [TenantAdminController::class, 'removeStaff'])->middleware('can:staff.manage');
        Route::get('suppliers', [TenantAdminController::class, 'suppliers'])->middleware('can:inventory.cost.view');
        Route::post('suppliers', [TenantAdminController::class, 'createSupplier'])->middleware('can:suppliers.manage');

        Route::get('inventory', [InventoryController::class, 'index'])->middleware('can:inventory.view');
        Route::post('inventory', [InventoryController::class, 'store'])->middleware('can:inventory.create');
        Route::get('inventory/{item}', [InventoryController::class, 'show'])->middleware('can:inventory.view');
        Route::get('inventory/{item}/ledger', [InventoryController::class, 'ledger'])->middleware('can:inventory.adjust');
        Route::post('inventory/{item}/adjustments', [InventoryController::class, 'adjust'])->middleware('can:inventory.adjust');
        Route::post('purchase-receipts', [InventoryController::class, 'receive'])->middleware('can:purchase.receive');

        Route::get('catalog/categories', [InventoryController::class, 'categories']);
        Route::get('catalog/search', [InventoryController::class, 'catalogSearch'])->middleware('can:inventory.view');
        Route::get('catalog/products/{product}', [InventoryController::class, 'product'])->middleware('can:inventory.view');
        Route::post('catalog/candidates', [InventoryController::class, 'createCandidate'])->middleware('can:inventory.create');

        Route::get('listings', [ListingController::class, 'index'])->middleware('can:inventory.view');
        Route::patch('listings/{listing}', [ListingController::class, 'update'])->middleware('can:listings.manage');

        Route::post('ai/analyses', [AiController::class, 'analyze'])->middleware(['can:ai.analyze', 'throttle:photo']);
        Route::get('ai/analyses/{analysis}', [AiController::class, 'show'])->middleware('can:ai.analyze');
        Route::post('ai/analyses/{analysis}/confirm', [AiController::class, 'confirm'])->middleware('can:ai.analyze');

        Route::get('pos/search', [PosController::class, 'search'])->middleware('can:pos.sell');
        Route::post('pos/photo-search', [PosController::class, 'photoSearch'])->middleware(['can:pos.sell', 'throttle:photo']);
        Route::post('pos/checkout', [PosController::class, 'checkout'])->middleware('can:pos.sell');
        Route::get('pos/registers', [PosController::class, 'registers'])->middleware('can:pos.session');
        Route::get('pos/sessions/current', [PosController::class, 'currentSession'])->middleware('can:pos.session');
        Route::post('pos/sessions', [PosController::class, 'openSession'])->middleware('can:pos.session');
        Route::post('pos/sessions/{session}/close', [PosController::class, 'closeSession'])->middleware('can:pos.session');
        Route::get('sales', [PosController::class, 'sales'])->middleware('can:sales.view');
        Route::get('sales/{sale}', [PosController::class, 'sale'])->middleware('can:sales.view');
        Route::post('sales/{sale}/void', [PosController::class, 'void'])->middleware('can:sales.void');

        Route::get('orders', [VendorOrders::class, 'index'])->middleware('can:orders.view');
        Route::get('orders/{order}', [VendorOrders::class, 'show'])->middleware('can:orders.view');
        Route::post('orders/{order}/transition', [VendorOrders::class, 'transition'])->middleware('can:orders.manage');

        Route::get('sync/snapshot', [SyncController::class, 'snapshot'])->defaults('kind', 'vendor');
        Route::get('sync/changes', [SyncController::class, 'changes'])->defaults('kind', 'vendor');
    });

    Route::prefix('admin')->middleware(['auth:sanctum', 'abilities:platform', 'platform_admin', 'throttle:api'])->group(function () {
        Route::get('health', [PlatformController::class, 'health']);
        Route::get('tenants', [PlatformController::class, 'tenants']);
        Route::post('tenants', [PlatformController::class, 'createTenant']);
        Route::patch('tenants/{tenant}/status', [PlatformController::class, 'setTenantStatus']);
        Route::get('catalog/candidates', [PlatformController::class, 'candidates']);
        Route::post('catalog/candidates/{product}/approve', [PlatformController::class, 'approveCandidate']);
    });
});
