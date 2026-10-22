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
use App\Services\MarketplaceSearch;
use App\Services\StockService;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceController extends Controller
{
    use ApiHelpers;

    public function __construct(private MarketplaceSearch $search, private AiIntakeService $ai) {}

    public function categories()
    {
        return CategoryResource::collection(Category::query()->orderBy('sort')->get());
    }

    public function search(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200'], 'brand' => ['nullable', 'string', 'max:80'], 'category' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:relevance,price_asc,price_desc'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return ProductResource::collection($this->search->products($request->only(['q', 'brand', 'category', 'sort']) + ['in_stock' => $request->boolean('in_stock'), 'per_page' => $this->perPage($request, 20)]))
            ->additional(['meta_extra' => ['server_time' => now()->toIso8601String()]]);
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

    public function store(int $store): PublicStoreResource
    {
        $s = Store::query()->withoutGlobalScopes()->with('tenant')->where('is_active', true)->whereHas('tenant', fn ($t) => $t->where('status', 'active'))->findOrFail($store);

        return new PublicStoreResource($s);
    }

    public function storeListings(Request $request, int $store)
    {
        $q = StockService::withAvailability(MarketplaceSearch::liveListings())->with(['store.tenant', 'variant.barcodes'])->where('tenant_listings.store_id', $store)->orderBy('tenant_listings.id');

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
        $analysis->candidates->isEmpty() && $analysis->classification === 'insufficient_confidence' && throw new ApiException('image_insufficient', $analysis->is_mock ? 'Demo AI can only recognise its built-in demo photos. Pick a demo photo, or connect a live model.' : 'That photo is not clear enough to identify. Try a closer, well-lit shot of the label.', 422, ['analysis' => (new AiAnalysisResource($analysis))->resolve()]);

        return response()->json(['data' => (new AiAnalysisResource($analysis))->resolve(), 'meta' => ['server_time' => now()->toIso8601String()]]);
    }
}
