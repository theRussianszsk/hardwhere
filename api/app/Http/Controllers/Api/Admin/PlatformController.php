<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TenantRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\TenantResource;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CatalogService;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Platform administrators: tenants and shared-catalog moderation. Never reads tenant inventory, costs, or sales. */
class PlatformController extends Controller
{
    public function __construct(private CatalogService $catalog, private AuditLogger $audit) {}

    public function tenants()
    {
        return TenantResource::collection(Tenant::query()->orderBy('name')->paginate(50));
    }

    public function createTenant(TenantRequest $request): JsonResponse
    {
        $d = $request->validated();
        $tenant = DB::transaction(function () use ($d, $request) {
            $tenant = Tenant::create(['name' => $d['name'], 'slug' => $d['slug']]);
            $owner = User::query()->firstOrCreate(['email' => $d['owner_email']], ['name' => $d['owner_name'], 'password' => $d['owner_password']]);
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => TenantRole::Owner->value]);
            $this->audit->record('tenant.created', $tenant->id, $tenant, [], $request->user()->id);

            return $tenant;
        });

        return (new TenantResource($tenant))->response()->setStatusCode(201);
    }

    public function setTenantStatus(Request $request, int $tenant): TenantResource
    {
        $status = $request->validate(['status' => ['required', 'in:active,suspended']])['status'];
        $t = Tenant::query()->findOrFail($tenant);
        $t->update(['status' => $status]);
        $this->audit->record("tenant.$status", $t->id, $t, [], $request->user()->id);

        return new TenantResource($t);
    }

    public function candidates()
    {
        return ProductResource::collection(Product::query()->where('status', 'candidate')->with(['category', 'variants.barcodes'])->latest()->paginate(50));
    }

    public function approveCandidate(Request $request, int $product): ProductResource
    {
        $p = Product::query()->where('status', 'candidate')->findOrFail($product);

        return new ProductResource($this->catalog->approve($p)->load(['category', 'variants.barcodes']));
    }

    public function health(): JsonResponse
    {
        $db = true;
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $db = false;
        }

        return response()->json(['data' => [
            'database' => $db ? 'ok' : 'down', 'ai_provider' => config('hardwhere.ai.provider'), 'ai_live' => match (config('hardwhere.ai.provider')) { 'gemma' => (bool) config('hardwhere.ai.gemma.api_key'), 'openrouter' => (bool) config('hardwhere.ai.openrouter.api_key'), default => false },
            'tenants' => Tenant::query()->count(), 'stores' => Store::query()->withoutGlobalScopes()->count(), 'candidate_products' => Product::query()->where('status', 'candidate')->count(),
            'locations' => InventoryLocation::query()->withoutGlobalScopes()->count(), 'server_time' => now()->toIso8601String(),
        ]]);
    }
}
