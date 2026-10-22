<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AnalyzeImageRequest;
use App\Http\Requests\Api\CheckoutRequest;
use App\Http\Requests\Api\CloseSessionRequest;
use App\Http\Requests\Api\SessionRequest;
use App\Http\Requests\Api\VoidRequest;
use App\Http\Resources\AiAnalysisResource;
use App\Http\Resources\PosRegisterResource;
use App\Http\Resources\PosSessionResource;
use App\Http\Resources\SaleResource;
use App\Http\Resources\TenantListingResource;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Sale;
use App\Models\TenantListing;
use App\Services\AiIntakeService;
use App\Services\PosSearch;
use App\Services\PosService;
use App\Services\StockService;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    use ApiHelpers;

    public function __construct(private PosService $pos, private PosSearch $search, private AiIntakeService $ai) {}

    private function storeId(Request $request): int
    {
        $ctx = $this->ctx();
        $id = (int) ($request->input('store_id', $request->query('store_id', $ctx->storeId)));
        $id > 0 || throw ApiException::invalid('store_required', 'Choose a store.');
        $ctx->assertStore($id);

        return $id;
    }

    public function search(Request $request)
    {
        $f = $request->only(['q', 'barcode', 'category']) + ['per_page' => $this->perPage($request, 30)];

        return TenantListingResource::collection($this->search->listings($this->ctx(), $this->storeId($request), $f));
    }

    /** Photo -> candidates constrained to variants THIS tenant carries, then mapped to listings sold at this store. */
    public function photoSearch(AnalyzeImageRequest $request): JsonResponse
    {
        $ctx = $this->ctx();
        $storeId = $this->storeId($request);
        $analysis = $this->ai->analyze($request->user(), $ctx, $request->file('image'), 'pos_search', $this->idempotencyKey($request), $request->validated('hint'), $this->ai->carriedBy($ctx->tenantId));

        $listings = StockService::withAvailability(TenantListing::query()->forTenant($ctx))->with(['variant.barcodes', 'product.category'])
            ->where('store_id', $storeId)->whereIn('variant_id', $analysis->candidates->pluck('variant_id')->filter())->get()->keyBy('variant_id');

        return response()->json([
            'analysis' => (new AiAnalysisResource($analysis))->resolve(),
            'results' => $analysis->candidates->map(fn ($c) => [
                'rank' => $c->rank, 'classification' => $c->classification, 'score' => $c->score, 'warnings' => $c->warnings,
                'listing' => isset($listings[$c->variant_id]) ? (new TenantListingResource($listings[$c->variant_id]))->resolve() : null,
            ])->values(),
        ]);
    }

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $ctx = $this->ctx();
        [$sale, $replayed] = $this->pos->checkout($ctx, $request->validated(), $this->idempotencyKey($request));
        $sale->setAttribute('low_stock', $this->pos->lowStock($ctx, $sale->store_id, $sale->items->pluck('inventory_item_id')->all()));

        return (new SaleResource($sale))->additional(['replayed' => $replayed])->response()->setStatusCode($replayed ? 200 : 201);
    }

    public function registers(Request $request)
    {
        $ctx = $this->ctx();

        return PosRegisterResource::collection(PosRegister::query()->forTenant($ctx)->when($ctx->storeId, fn ($q) => $q->where('store_id', $ctx->storeId))->orderBy('name')->get());
    }

    public function currentSession(Request $request): JsonResponse
    {
        $ctx = $this->ctx();
        $session = PosSession::query()->forTenant($ctx)->whereNull('closed_at')->where('opened_by', $ctx->userId)->latest('id')->first();

        return response()->json(['data' => $session ? (new PosSessionResource($session))->resolve() : null]);
    }

    public function openSession(SessionRequest $request): JsonResponse
    {
        $ctx = $this->ctx();
        $register = PosRegister::query()->forTenant($ctx)->findOrFail($request->validated('register_id'));
        $ctx->assertStore($register->store_id);
        PosSession::query()->forTenant($ctx)->where('register_id', $register->id)->whereNull('closed_at')->exists()
            && throw ApiException::conflict('session_open', 'This register already has an open session.');

        $session = PosSession::create(['tenant_id' => $ctx->tenantId, 'register_id' => $register->id, 'opened_by' => $ctx->userId, 'opened_at' => now(), 'opening_float_cents' => $request->validated('opening_float_cents') ?? 0]);

        return (new PosSessionResource($session))->response()->setStatusCode(201);
    }

    public function closeSession(CloseSessionRequest $request, int $session): PosSessionResource
    {
        $ctx = $this->ctx();

        return new PosSessionResource(DB::transaction(function () use ($ctx, $request, $session) {
            $s = PosSession::query()->forTenant($ctx)->whereNull('closed_at')->with('register')->lockForUpdate()->findOrFail($session);
            $ctx->assertStore($s->register->store_id);
            $s->opened_by === $ctx->userId || $ctx->can('sales.view_all') || throw ApiException::forbidden('Only the cashier who opened this session (or a manager) can close it.');
            $s->update(['closed_at' => now(), 'closing_cash_cents' => $request->validated('closing_cash_cents')]);

            return $s;
        }));
    }

    public function sales(Request $request)
    {
        $ctx = $this->ctx();
        $q = Sale::query()->forTenant($ctx)->with('items')->orderByDesc('completed_at')
            ->when($ctx->storeId, fn ($w) => $w->where('store_id', $ctx->storeId))
            ->when(! $ctx->can('sales.view_all'), fn ($w) => $w->where('cashier_id', $ctx->userId))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));

        return SaleResource::collection($q->paginate($this->perPage($request)));
    }

    public function sale(int $sale): SaleResource
    {
        $ctx = $this->ctx();
        $s = Sale::query()->forTenant($ctx)->with('items')->findOrFail($sale);
        $ctx->assertStore($s->store_id);
        $ctx->can('sales.view_all') || $s->cashier_id === $ctx->userId || throw ApiException::forbidden();

        return new SaleResource($s);
    }

    public function void(VoidRequest $request, int $sale): SaleResource
    {
        return new SaleResource($this->pos->void($this->ctx(), $sale, $request->validated('reason')));
    }
}
