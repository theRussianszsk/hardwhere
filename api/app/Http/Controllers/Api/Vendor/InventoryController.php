<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdjustStockRequest;
use App\Http\Requests\Api\CandidateRequest;
use App\Http\Requests\Api\PurchaseReceiptRequest;
use App\Http\Requests\Api\StoreItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\LedgerEntryResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PurchaseReceiptResource;
use App\Http\Resources\VariantResource;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\StockLedgerEntry;
use App\Services\CatalogService;
use App\Services\Idempotency;
use App\Services\InventoryService;
use App\Support\ApiException;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use ApiHelpers;

    public function __construct(private InventoryService $inventory, private CatalogService $catalog, private Idempotency $idempotency) {}

    public function index(Request $request)
    {
        $ctx = $this->ctx();
        $q = InventoryItem::query()->forTenant($ctx)->with(['variant.product', 'variant.barcodes']);
        $locationIds = InventoryLocation::query()->forTenant($ctx)->when($ctx->storeId, fn ($w) => $w->where('store_id', $ctx->storeId))->pluck('id');
        $q->with(['snapshots' => fn ($s) => $s->whereIn('location_id', $locationIds)]);

        if ($term = trim((string) $request->query('q'))) {
            $text = Text::normalize($term);
            $q->where(function ($w) use ($term, $text) {
                $w->whereRaw('lower(inventory_items.tenant_sku) LIKE ?', [mb_strtolower($term).'%'])
                    ->orWhereHas('variant.barcodes', fn ($b) => $b->where('code', $term))
                    ->orWhereHas('variant.product', function ($p) use ($text) {
                        foreach (explode(' ', $text) as $t) {
                            $p->whereRaw('products.search_text ILIKE ?', ['%'.$t.'%']);
                        }
                    });
            });
        }
        if ($request->boolean('low_stock')) {
            $q->whereRaw('(SELECT COALESCE(SUM(on_hand - reserved), 0) FROM stock_snapshots WHERE inventory_item_id = inventory_items.id) <= inventory_items.reorder_threshold');
        }
        match ($request->query('sort')) {
            'updated' => $q->orderByDesc('inventory_items.updated_at'),
            default => $q->orderBy('inventory_items.tenant_sku'),
        };

        return InventoryItemResource::collection($q->paginate($this->perPage($request)));
    }

    public function show(int $item): InventoryItemResource
    {
        $ctx = $this->ctx();

        return new InventoryItemResource(InventoryItem::query()->forTenant($ctx)->with(['variant.product', 'variant.barcodes', 'snapshots'])->findOrFail($item));
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $d = $request->validated();
        $item = $this->inventory->createItem($this->ctx(), $d['variant_id'], $d + ['source' => 'manual']);

        return (new InventoryItemResource($item))->response()->setStatusCode(201);
    }

    public function ledger(Request $request, int $item)
    {
        $ctx = $this->ctx();
        InventoryItem::query()->forTenant($ctx)->findOrFail($item);

        return LedgerEntryResource::collection(StockLedgerEntry::query()->forTenant($ctx)->where('inventory_item_id', $item)->orderByDesc('id')->paginate($this->perPage($request, 30)));
    }

    public function adjust(AdjustStockRequest $request, int $item): JsonResponse
    {
        $ctx = $this->ctx();
        $d = $request->validated();
        [$entry, $replayed] = $this->idempotency->run("t{$ctx->tenantId}", $this->idempotencyKey($request), 'stock.adjust', Idempotency::hash($d + ['item' => $item]), StockLedgerEntry::class,
            fn () => $this->inventory->adjust($ctx, $item, $d['location_id'], $d['movement_type'], $d['quantity_delta'], $d['note'] ?? null));

        return (new LedgerEntryResource($entry))->additional(['replayed' => $replayed])->response()->setStatusCode($replayed ? 200 : 201);
    }

    public function receive(PurchaseReceiptRequest $request): JsonResponse
    {
        $ctx = $this->ctx();
        $d = $request->validated();
        [$receipt, $replayed] = $this->idempotency->run("t{$ctx->tenantId}", $this->idempotencyKey($request), 'purchase.receive', Idempotency::hash($d), \App\Models\PurchaseReceipt::class,
            fn () => $this->inventory->receivePurchase($ctx, $d));

        return (new PurchaseReceiptResource($receipt))->additional(['replayed' => $replayed])->response()->setStatusCode($replayed ? 200 : 201);
    }

    /** Canonical catalog lookup by barcode / SKU / text for the "add item" and review screens. */
    public function catalogSearch(Request $request): JsonResponse
    {
        $ctx = $this->ctx();
        $term = trim((string) $request->query('q'));
        $text = Text::normalize($term);
        $key = Text::key($term);

        $variants = ProductVariant::query()->with(['product.category', 'barcodes'])
            ->whereHas('product', fn ($p) => $p->where('status', 'approved')->orWhere('submitted_by_tenant_id', $ctx->tenantId))
            ->where(function ($w) use ($term, $text, $key) {
                $w->whereHas('barcodes', fn ($b) => $b->where('code', $term))
                    ->orWhereRaw("regexp_replace(lower(coalesce(model_number,'')), '[^a-z0-9]', '', 'g') LIKE ?", [$key.'%'])
                    ->orWhereHas('product', function ($p) use ($text) {
                        foreach (explode(' ', $text) as $t) {
                            $p->whereRaw('products.search_text ILIKE ?', ['%'.$t.'%']);
                        }
                    });
            })->limit(25)->get();

        return response()->json(['data' => $variants->map(fn ($v) => ['product' => (new ProductResource($v->product))->resolve(), 'variant' => (new VariantResource($v))->resolve()])->values()]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => \App\Models\Category::query()->orderBy('sort')->get(['id', 'parent_id', 'slug', 'name'])]);
    }

    /** Tenant-proposed product. Stays a private 'candidate' until a platform admin approves it into the shared catalog. */
    public function createCandidate(CandidateRequest $request): JsonResponse
    {
        $ctx = $this->ctx();
        $ctx->can('inventory.create') || throw ApiException::forbidden();
        $d = $request->validated();
        foreach ($d['variants'] ?? [] as $v) {
            foreach (array_keys($v['barcodes'] ?? []) as $code) {
                ProductBarcode::query()->where('code', (string) $code)->exists() && throw ApiException::conflict('barcode_conflict', "Barcode $code already belongs to another catalog product.");
            }
        }
        $product = $this->catalog->createProduct($d, $ctx->tenantId);

        return (new ProductResource($product->load('category')))->response()->setStatusCode(201);
    }

    public function product(int $product): ProductResource
    {
        $ctx = $this->ctx();

        return new ProductResource(Product::query()->with(['category', 'variants.barcodes', 'images'])
            ->where(fn ($q) => $q->where('status', 'approved')->orWhere('submitted_by_tenant_id', $ctx->tenantId))->findOrFail($product));
    }
}
