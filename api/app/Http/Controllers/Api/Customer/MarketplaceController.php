<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AnalyzeImageRequest;
use App\Http\Resources\AiAnalysisResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PublicListingResource;
use App\Http\Resources\PublicStoreResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\AiIntakeService;
use App\Services\DemandMonitor;
use App\Services\MarketplaceSearch;
use App\Services\StockService;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceController extends Controller
{
    use ApiHelpers;

    public function __construct(private MarketplaceSearch $search, private AiIntakeService $ai, private DemandMonitor $demand) {}

    public function categories()
    {
        return CategoryResource::collection(Category::query()->orderBy('sort')->get());
    }

    public function search(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200'], 'brand' => ['nullable', 'string', 'max:80'], 'model' => ['nullable', 'string', 'max:80'], 'category' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:relevance,nearest,price_asc,price_desc'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng,max_km'], 'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat,max_km'],
            'max_km' => ['nullable', 'numeric', 'min:0.1', 'max:1000'], 'min_price_cents' => ['nullable', 'integer', 'min:0'],
            'max_price_cents' => ['nullable', 'integer', 'min:0', ...($request->filled('min_price_cents') ? ['gte:min_price_cents'] : [])], 'fulfillment' => ['nullable', 'in:pickup,delivery'],
            'spec' => ['nullable', 'array', 'max:8'], 'spec.*' => ['string', 'max:60']]);
        $specs = collect($request->query('spec', []))->filter(fn ($v, $k) => is_string($k) && preg_match('/^[a-z0-9_]{1,40}$/', $k) && $v !== '')->all();
        $geo = $request->filled('lat') && $request->filled('lng') ? ['lat' => (float) $request->query('lat'), 'lng' => (float) $request->query('lng')] : [];
        $page = $this->search->products($request->only(['q', 'brand', 'model', 'category', 'sort', 'fulfillment']) + $geo + ['spec' => $specs, 'in_stock' => $request->boolean('in_stock'),
            'max_km' => $request->filled('max_km') ? (float) $request->query('max_km') : null,
            'min_price_cents' => $request->filled('min_price_cents') ? (int) $request->query('min_price_cents') : null,
            'max_price_cents' => $request->filled('max_price_cents') ? (int) $request->query('max_price_cents') : null, 'per_page' => $this->perPage($request, 20)]);
        // Nothing on sale: say whether the catalog knows the product, so the app can tell the shopper no registered store sells it yet.
        $unlisted = $page->total() === 0 && $request->filled('q') ? $this->search->unlistedMatches((string) $request->query('q'), $request->query('category')) : collect();

        $this->recordDemand(fn () => $this->demand->recordTextSearch(DemandMonitor::searcherKey($request), $request->only(['q', 'brand', 'category']), $page));

        return ProductResource::collection($page)->additional(['meta_extra' => ['server_time' => now()->toIso8601String(),
            'unlisted_matches' => $unlisted->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'brand' => $p->brand, 'model_number' => $p->model_number])->values()]]);
    }

    /** Choices for the detailed search form (brands and spec values that stores currently list), optionally narrowed to a category. */
    public function searchFilters(Request $request): JsonResponse
    {
        $request->validate(['category' => ['nullable', 'string', 'max:80']]);

        return response()->json(['data' => $this->search->filters($request->query('category')), 'meta' => ['server_time' => now()->toIso8601String()]]);
    }

    public function product(int $product): ProductResource
    {
        return new ProductResource(Product::query()->where('status', 'approved')->with(['category', 'variants.barcodes', 'images'])->findOrFail($product));
    }

    /** Store comparison: every live listing of the product with price, availability bucket and (optionally) distance. */
    public function listings(Request $request, int $product): JsonResponse
    {
        $lat = $request->filled('lat') ? (float) $request->query('lat') : null;
        $lng = $request->filled('lng') ? (float) $request->query('lng') : null;
        $listings = $this->search->listingsForProduct($product, $lat, $lng);

        return response()->json(['data' => PublicListingResource::collection($listings)->resolve(), 'meta' => ['server_time' => now()->toIso8601String(), 'count' => $listings->count()]]);
    }

    /** Store directory: active stores of active businesses with their live listing count; nearest first when a location is given. */
    public function stores(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $live = MarketplaceSearch::liveListings()->whereColumn('tenant_listings.store_id', 'stores.id')->selectRaw('count(*)');
        $q = Store::query()->withoutGlobalScopes()->with('tenant')->select('stores.*')->selectSub($live, 'listing_count')
            ->join('tenants', 'tenants.id', '=', 'stores.tenant_id')->where('tenants.status', 'active')->whereNull('tenants.deleted_at')
            ->where('stores.is_active', true)->whereNull('stores.deleted_at');
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($s, '%_\\').'%';
            $q->where(fn ($w) => $w->where('stores.name', 'ilike', $like)->orWhere('tenants.name', 'ilike', $like)->orWhere('stores.city', 'ilike', $like)
                ->orWhere('stores.barangay', 'ilike', $like)->orWhere('stores.province', 'ilike', $like));
        }
        if ($request->filled('lat') && $request->filled('lng')) {
            $lat = (float) $request->query('lat');
            $q->selectRaw('6371 * 2 * asin(sqrt(power(sin(radians(stores.lat - ?) / 2), 2) + cos(radians(?)) * cos(radians(stores.lat)) * power(sin(radians(stores.lng - ?) / 2), 2))) AS distance_km', [$lat, $lat, (float) $request->query('lng')])
                ->orderByRaw('distance_km ASC NULLS LAST');
        }

        return PublicStoreResource::collection($q->orderBy('tenants.name')->orderBy('stores.name')->orderBy('stores.id')->paginate($this->perPage($request, 20)))
            ->additional(['meta_extra' => ['server_time' => now()->toIso8601String()]]);
    }

    public function store(int $store): PublicStoreResource
    {
        $s = Store::query()->withoutGlobalScopes()->with('tenant')->where('is_active', true)->whereHas('tenant', fn ($t) => $t->where('status', 'active'))->findOrFail($store);

        return new PublicStoreResource($s);
    }

    public function storeListings(Request $request, int $store)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $q = StockService::withAvailability(MarketplaceSearch::liveListings())->with(['store.tenant', 'variant.barcodes', 'product.images'])->where('tenant_listings.store_id', $store)->orderBy('tenant_listings.id');
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($s, '%_\\').'%';
            $q->where(fn ($w) => $w->where('tenant_listings.title_override', 'ilike', $like)->orWhere('tenant_listings.tenant_sku', 'ilike', $like)
                ->orWhereExists(fn ($p) => $p->select(DB::raw(1))->from('products')->whereColumn('products.id', 'tenant_listings.product_id')
                    ->where(fn ($x) => $x->where('products.name', 'ilike', $like)->orWhere('products.brand', 'ilike', $like)->orWhere('products.model_number', 'ilike', $like)))
                ->orWhereExists(fn ($v) => $v->select(DB::raw(1))->from('product_variants')->whereColumn('product_variants.id', 'tenant_listings.variant_id')->where('product_variants.name', 'ilike', $like)));
        }

        return PublicListingResource::collection($q->paginate($this->perPage($request)));
    }

    public function listing(int $listing): PublicListingResource
    {
        return new PublicListingResource(StockService::withAvailability(MarketplaceSearch::liveListings())->with(['store.tenant', 'variant.barcodes'])->findOrFail($listing));
    }

    /** Photo search needs a signed-in customer: each call can cost an AI request. Candidates are limited to products someone currently lists. */
    public function photoSearch(AnalyzeImageRequest $request): JsonResponse
    {
        $analysis = $this->ai->analyze($request->user(), null, $request->file('image'), 'marketplace_search', $this->idempotencyKey($request), $request->validated('hint'), $this->ai->publiclyListed());
        $this->recordDemand(fn () => $this->demand->recordPhotoSearch(DemandMonitor::searcherKey($request), $analysis));
        $analysis->candidates->isEmpty() && $analysis->classification === 'insufficient_confidence' && throw new ApiException('image_insufficient', $analysis->is_mock ? 'Demo AI can only recognise its built-in demo photos. Pick a demo photo, or connect a live model.' : 'That photo is not clear enough to identify. Try a closer, well-lit shot of the label.', 422, ['analysis' => (new AiAnalysisResource($analysis))->resolve()]);

        return response()->json(['data' => (new AiAnalysisResource($analysis))->resolve(), 'meta' => ['server_time' => now()->toIso8601String()]]);
    }

    /** Demand tracking is a side channel: a failure there must never fail the customer's search. */
    private function recordDemand(\Closure $record): void
    {
        try {
            $record();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
