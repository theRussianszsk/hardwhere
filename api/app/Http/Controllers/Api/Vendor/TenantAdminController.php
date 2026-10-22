<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\TenantRole;
use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StaffRequest;
use App\Http\Requests\Api\StaffRoleRequest;
use App\Http\Requests\Api\StoreRequest;
use App\Http\Requests\Api\SupplierRequest;
use App\Http\Resources\StoreResource;
use App\Http\Resources\SupplierResource;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\PersonalAccessToken;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TenantListing;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SyncRecorder;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TenantAdminController extends Controller
{
    use ApiHelpers;

    public function __construct(private AuditLogger $audit, private SyncRecorder $sync) {}

    public function dashboard(): JsonResponse
    {
        $ctx = $this->ctx();
        $today = now('Asia/Manila')->startOfDay()->utc();
        $sales = Sale::query()->forTenant($ctx)->where('status', 'completed')->where('completed_at', '>=', $today)
            ->when($ctx->storeId, fn ($q) => $q->where('store_id', $ctx->storeId));
        $lowStock = InventoryItem::query()->forTenant($ctx)->whereRaw('(SELECT COALESCE(SUM(on_hand - reserved), 0) FROM stock_snapshots WHERE inventory_item_id = inventory_items.id) <= reorder_threshold')->count();

        return response()->json(['data' => [
            'sales_today_count' => (clone $sales)->count(),
            'sales_today_cents' => (int) (clone $sales)->sum('total_cents'),
            'low_stock_count' => $lowStock,
            'open_orders' => $ctx->can('orders.view') ? Order::query()->forTenant($ctx)->whereIn('status', ['reserved', 'confirmed', 'preparing', 'ready_for_pickup'])->count() : null,
            'published_listings' => TenantListing::query()->forTenant($ctx)->where('publication_status', 'published')->distinct()->count('inventory_item_id'),
            'inventory_items' => InventoryItem::query()->forTenant($ctx)->count(),
        ]]);
    }

    public function stores(): AnonymousResourceCollection
    {
        $ctx = $this->ctx();

        return StoreResource::collection(Store::query()->forTenant($ctx)->when($ctx->storeId, fn ($q) => $q->whereKey($ctx->storeId))->with([])->orderBy('name')->get());
    }

    public function locations(): JsonResponse
    {
        $ctx = $this->ctx();

        return response()->json(['data' => InventoryLocation::query()->forTenant($ctx)->when($ctx->storeId, fn ($q) => $q->where('store_id', $ctx->storeId))->orderBy('id')->get(['id', 'store_id', 'name', 'code', 'is_sellable'])]);
    }

    public function createStore(StoreRequest $request): JsonResponse
    {
        $ctx = $this->ctx();
        $d = $request->validated();
        if (Store::query()->forTenant($ctx)->where('code', $d['code'])->exists()) {
            throw ApiException::conflict('store_code_taken', 'That store code is already used.');
        }

        $store = DB::transaction(function () use ($ctx, $d) {
            $store = Store::create($d + ['tenant_id' => $ctx->tenantId, 'hours' => $d['hours'] ?? []]);
            InventoryLocation::create(['tenant_id' => $ctx->tenantId, 'store_id' => $store->id, 'name' => 'Main floor', 'code' => 'MAIN']);
            $this->sync->tenantEntity($ctx->tenantId, 'store', $store->id);
            $this->sync->publicEntity('store', $store->id);
            $this->audit->record('store.created', $ctx, $store);

            return $store;
        });

        return (new StoreResource($store))->response()->setStatusCode(201);
    }

    public function updateStore(StoreRequest $request, int $store): StoreResource
    {
        $ctx = $this->ctx();
        $model = Store::query()->forTenant($ctx)->findOrFail($store);
        $ctx->assertStore($model->id);
        $d = $request->validated();
        if (Store::query()->forTenant($ctx)->where('code', $d['code'])->whereKeyNot($model->id)->exists()) {
            throw ApiException::conflict('store_code_taken', 'That store code is already used.');
        }

        DB::transaction(function () use ($ctx, $model, $d) {
            $model->fill($d)->forceFill(['row_version' => $model->row_version + 1])->save();
            $this->sync->tenantEntity($ctx->tenantId, 'store', $model->id);
            $this->sync->publicEntity('store', $model->id);
            $this->audit->record('store.updated', $ctx, $model, array_keys($d));
        });

        return new StoreResource($model);
    }

    public function staff(): JsonResponse
    {
        $ctx = $this->ctx();

        return response()->json(['data' => TenantMembership::query()->with('user:id,name,email')->where('tenant_id', $ctx->tenantId)->orderBy('id')->get()
            ->map(fn ($m) => ['id' => $m->id, 'user_id' => $m->user_id, 'name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->role, 'store_id' => $m->store_id])]);
    }

    public function addStaff(StaffRequest $request): JsonResponse
    {
        $ctx = $this->ctx();
        $d = $request->validated();
        if (isset($d['store_id']) && ! Store::query()->forTenant($ctx)->whereKey($d['store_id'])->exists()) {
            throw ApiException::invalid('unknown_store', 'Unknown store.');
        }

        $membership = DB::transaction(function () use ($ctx, $d) {
            $user = User::query()->where('email', $d['email'])->first();
            if (! $user) {
                isset($d['name'], $d['password']) || throw ApiException::invalid('new_user_needs_name_and_password', 'A name and initial password are required for a new account.');
                $user = User::create(['name' => $d['name'], 'email' => $d['email'], 'password' => $d['password']]);
                $user->forceFill(['email_verified_at' => now()])->save(); // the owner supplied and vouches for this address
            }
            if (! $user->wasRecentlyCreated && $user->email_verified_at === null) {
                throw ApiException::invalid('user_unverified', 'That account has not verified its email address, so it cannot be added to a business. Create a new account for them instead.');
            }
            if (TenantMembership::query()->where('tenant_id', $ctx->tenantId)->where('user_id', $user->id)->exists()) {
                throw ApiException::conflict('already_member', 'This person is already on your team.');
            }
            $m = TenantMembership::create(['tenant_id' => $ctx->tenantId, 'user_id' => $user->id, 'role' => $d['role'], 'store_id' => $d['store_id'] ?? null]);
            $this->audit->record('staff.added', $ctx, $m, ['role' => $d['role']]);

            return $m;
        });

        return response()->json(['data' => ['id' => $membership->id, 'user_id' => $membership->user_id, 'role' => $membership->role, 'store_id' => $membership->store_id]], 201);
    }

    public function updateStaff(StaffRoleRequest $request, int $membership): JsonResponse
    {
        $ctx = $this->ctx();
        $m = TenantMembership::query()->where('tenant_id', $ctx->tenantId)->findOrFail($membership);
        $m->role === TenantRole::Owner->value && throw ApiException::forbidden('The owner role cannot be changed here.');
        $sid = $request->validated('store_id');
        if ($sid !== null && ! Store::query()->forTenant($ctx)->whereKey($sid)->exists()) {
            throw ApiException::invalid('unknown_store', 'Unknown store.');
        }
        $m->update(['role' => $request->validated('role'), 'store_id' => $request->validated('store_id')]);
        $this->audit->record('staff.updated', $ctx, $m, ['role' => $m->role]);

        return response()->json(['data' => ['id' => $m->id, 'role' => $m->role, 'store_id' => $m->store_id]]);
    }

    /** Revokes the membership AND every device token issued for it, so cached sessions stop working at once. */
    public function removeStaff(Request $request, int $membership): JsonResponse
    {
        $ctx = $this->ctx();
        $m = TenantMembership::query()->where('tenant_id', $ctx->tenantId)->findOrFail($membership);
        $m->role === TenantRole::Owner->value && throw ApiException::forbidden('The owner cannot be removed.');
        DB::transaction(function () use ($ctx, $m) {
            PersonalAccessToken::query()->where('tenant_id', $ctx->tenantId)->where('tokenable_id', $m->user_id)->delete();
            $m->delete();
            $this->audit->record('staff.removed', $ctx, $m);
        });

        return response()->json(['ok' => true]);
    }

    public function suppliers(): AnonymousResourceCollection
    {
        return SupplierResource::collection(Supplier::query()->forTenant($this->ctx())->orderBy('name')->get());
    }

    public function createSupplier(SupplierRequest $request): JsonResponse
    {
        $ctx = $this->ctx();
        $s = Supplier::create($request->validated() + ['tenant_id' => $ctx->tenantId]);
        $this->audit->record('supplier.created', $ctx, $s);

        return (new SupplierResource($s))->response()->setStatusCode(201);
    }
}
