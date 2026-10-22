<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateListingRequest;
use App\Http\Resources\TenantListingResource;
use App\Models\TenantListing;
use App\Services\InventoryService;
use App\Services\StockService;
use App\Support\Text;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    use ApiHelpers;

    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request)
    {
        $ctx = $this->ctx();
        $q = StockService::withAvailability(TenantListing::query()->forTenant($ctx))->with(['variant.barcodes', 'product.category'])
            ->when($ctx->storeId, fn ($w) => $w->where('tenant_listings.store_id', $ctx->storeId))
            ->when($request->query('store_id'), fn ($w, $id) => $w->where('tenant_listings.store_id', $id))
            ->when($request->query('status'), fn ($w, $s) => $w->where('publication_status', $s))
            ->when($request->query('q'), fn ($w, $t) => $w->where(fn ($g) => $g->whereRaw('lower(tenant_listings.tenant_sku) LIKE ?', [mb_strtolower($t).'%'])
                ->orWhereHas('product', fn ($p) => $p->whereRaw('products.search_text ILIKE ?', ['%'.Text::normalize($t).'%']))))
            ->orderBy('tenant_listings.tenant_sku');

        return TenantListingResource::collection($q->paginate($this->perPage($request)));
    }

    public function update(UpdateListingRequest $request, int $listing): TenantListingResource
    {
        $updated = $this->inventory->updateListing($this->ctx(), $listing, $request->validated());

        return new TenantListingResource($updated->load(['variant.barcodes', 'product.category']));
    }
}
