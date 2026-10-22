<?php

namespace App\Services;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PublicListingResource;
use App\Http\Resources\PublicStoreResource;
use App\Http\Resources\StoreResource;
use App\Http\Resources\TenantListingResource;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\TenantListing;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that decides what a given audience may hold in its local cache. Returning null means "not visible
 * (any more)": the client receives a tombstone and deletes the row — that is how revoked visibility propagates.
 */
class SyncProjector
{
    public const ENTITIES = [
        'vendor' => ['category', 'product', 'store', 'listing', 'inventory_item', 'order'],
        'customer' => ['category', 'product', 'store', 'listing', 'order'],
        'public' => ['category', 'product', 'store', 'listing'],
    ];

    /** @return list<string> */
    public static function audiences(string $kind, ?int $tenantId, ?int $userId): array
    {
        return match ($kind) {
            'vendor' => ['catalog', "tenant:$tenantId"],
            'customer' => ['catalog', 'public', "user:$userId"],
            default => ['catalog', 'public'],
        };
    }

    /** @return array<string, mixed>|null */
    public function project(string $kind, string $entity, int $id, ?TenantContext $ctx, ?int $userId): ?array
    {
        $model = $this->query($kind, $entity, $ctx, $userId)?->find($id);
        $model ??= null;

        return $model ? $this->resource($kind, $entity, $model)->resolve() : null;
    }

    /**
     * Keyset page of everything visible for an entity (initial snapshot).
     *
     * @return array{items: list<array<string, mixed>>, next_after: ?int}
     */
    public function snapshotPage(string $kind, string $entity, int $afterId, int $limit, ?TenantContext $ctx, ?int $userId): array
    {
        $q = $this->query($kind, $entity, $ctx, $userId);
        if (! $q) {
            return ['items' => [], 'next_after' => null];
        }
        $table = $q->getModel()->getTable();
        $rows = $q->where("$table.id", '>', $afterId)->orderBy("$table.id")->limit($limit + 1)->get();
        $page = $rows->take($limit);

        return [
            'items' => $page->map(fn ($m) => $this->resource($kind, $entity, $m)->resolve())->values()->all(),
            'next_after' => $rows->count() > $limit ? $page->last()->id : null,
        ];
    }

    /** @return Builder<*>|null */
    private function query(string $kind, string $entity, ?TenantContext $ctx, ?int $userId): ?Builder
    {
        $vendor = $kind === 'vendor';
        $store = fn (Builder $q) => $ctx?->storeId ? $q->where($q->getModel()->getTable() === 'stores' ? 'id' : 'store_id', $ctx->storeId) : $q;

        return match ($entity) {
            'category' => Category::query(),
            'product' => Product::query()->with(['category', 'images', 'variants.barcodes'])
                ->where(fn ($q) => $q->where('status', 'approved')->when($vendor, fn ($w) => $w->orWhere('submitted_by_tenant_id', $ctx->tenantId))),
            'store' => $vendor
                ? $store(Store::query()->forTenant($ctx)->where('is_active', true))
                : Store::query()->withoutGlobalScopes()->with('tenant')->where('is_active', true)->whereHas('tenant', fn ($t) => $t->where('status', 'active')),
            'listing' => $vendor
                ? $store(StockService::withAvailability(TenantListing::query()->forTenant($ctx))->with(['variant.barcodes', 'product.category']))
                : StockService::withAvailability(MarketplaceSearch::liveListings())->with(['store.tenant', 'variant.barcodes']),
            'inventory_item' => $vendor ? InventoryItem::query()->forTenant($ctx)->with(['variant.product', 'variant.barcodes', 'snapshots' => fn ($s) => $ctx->storeId === null ? $s
                : $s->whereIn('location_id', InventoryLocation::query()->forTenant($ctx)->where('store_id', $ctx->storeId)->select('id'))]) : null,
            'order' => match (true) {
                $vendor => $ctx->can('orders.view') ? $store(Order::query()->forTenant($ctx)->with(['items', 'reservation'])) : null,
                $kind === 'customer' => Order::query()->withoutGlobalScopes()->where('customer_id', $userId)->with(['items', 'reservation', 'store.tenant']),
                default => null,
            },
            default => null,
        };
    }

    private function resource(string $kind, string $entity, $model)
    {
        $vendor = $kind === 'vendor';

        return match ($entity) {
            'category' => new CategoryResource($model),
            'product' => new ProductResource($model),
            'store' => $vendor ? new StoreResource($model) : new PublicStoreResource($model),
            'listing' => $vendor ? new TenantListingResource($model) : new PublicListingResource($model),
            'inventory_item' => new InventoryItemResource($model),
            'order' => new OrderResource($model),
        };
    }
}
