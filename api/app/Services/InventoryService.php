<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\Store;
use App\Models\TenantListing;
use App\Models\TenantProductMapping;
use App\Support\ApiException;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(private StockService $stock, private SyncRecorder $sync, private AuditLogger $audit) {}

    /**
     * Stock a canonical variant for this tenant at one store: item + mapping provenance + draft listing + opening balance, all or nothing.
     * The item is tenant-wide (one per variant) while each store sells it through its own listing and holds its own stock, so stocking an
     * item the tenant already carries at another store adds that store's listing and opening balance to the existing item.
     * Used by manual entry and by AI-assisted intake after explicit human confirmation.
     *
     * @param  array<string, mixed>  $d  tenant_sku, cost_cents, price_cents, quantity, reorder_threshold, supplier_id?, store_id, location_id?, source, ai_analysis_id?, confidence?
     */
    public function createItem(TenantContext $ctx, int $variantId, array $d): InventoryItem
    {
        $ctx->can('inventory.create') || throw ApiException::forbidden();
        $ctx->assertStore((int) $d['store_id']);

        return DB::transaction(function () use ($ctx, $variantId, $d) {
            $variant = ProductVariant::query()->with('product')->whereHas('product', fn ($q) => $q->where('status', 'approved')->orWhere('submitted_by_tenant_id', $ctx->tenantId))->find($variantId)
                ?? throw ApiException::invalid('unknown_variant', 'That catalog product is not available.');
            $store = Store::query()->forTenant($ctx)->find($d['store_id']) ?? throw ApiException::notFound('Store');
            $location = isset($d['location_id'])
                ? InventoryLocation::query()->forTenant($ctx)->where('store_id', $store->id)->find($d['location_id'])
                : InventoryLocation::query()->forTenant($ctx)->where('store_id', $store->id)->orderBy('id')->first();
            $location ?? throw ApiException::invalid('unknown_location', 'Choose a stock location in this store.');

            // Locked so two stores adding the same existing item at once serialise here instead of racing on the listing index.
            if ($item = InventoryItem::query()->forTenant($ctx)->where('variant_id', $variant->id)->lockForUpdate()->first()) {
                if (TenantListing::query()->forTenant($ctx)->where('store_id', $store->id)->where('variant_id', $variant->id)->exists()) {
                    throw ApiException::conflict('already_stocked', 'This store already stocks this product. Adjust its stock instead of adding it again.', ['inventory_item_id' => $item->id]);
                }
            } else {
                if (InventoryItem::query()->forTenant($ctx)->where('tenant_sku', $d['tenant_sku'])->exists()) {
                    throw ApiException::conflict('sku_taken', 'That SKU is already used by another item.');
                }
                $item = InventoryItem::create([
                    'tenant_id' => $ctx->tenantId, 'variant_id' => $variant->id, 'tenant_sku' => $d['tenant_sku'], 'cost_cents' => $d['cost_cents'] ?? 0,
                    'supplier_id' => $d['supplier_id'] ?? null, 'reorder_threshold' => $d['reorder_threshold'] ?? 0, 'default_location_id' => $location->id,
                ]);
                TenantProductMapping::create([
                    'tenant_id' => $ctx->tenantId, 'inventory_item_id' => $item->id, 'variant_id' => $variant->id, 'source' => $d['source'] ?? 'manual',
                    'ai_analysis_id' => $d['ai_analysis_id'] ?? null, 'confidence' => $d['confidence'] ?? null, 'confirmed_by' => $ctx->userId, 'confirmed_at' => now(),
                ]);
            }

            // An existing item keeps its tenant-wide SKU, cost and reorder threshold; the new store brings only its own price and stock.
            $listing = TenantListing::create([
                'tenant_id' => $ctx->tenantId, 'store_id' => $store->id, 'inventory_item_id' => $item->id, 'product_id' => $variant->product_id,
                'variant_id' => $variant->id, 'tenant_sku' => $item->tenant_sku, 'price_cents' => $d['price_cents'], 'uom' => $variant->uom,
                'visibility' => 'hidden', 'publication_status' => 'draft',
            ]);

            if (($d['quantity'] ?? 0) > 0) {
                $this->stock->adjust($ctx->tenantId, $ctx->userId, $item->id, $location->id, 'opening_balance', (int) $d['quantity'], 'Opening balance', 'inventory_item', $item->id);
            }

            $this->sync->tenantEntity($ctx->tenantId, 'inventory_item', $item->id);
            $this->sync->listing($ctx->tenantId, $listing->id);
            $this->audit->record($item->wasRecentlyCreated ? 'inventory.item.created' : 'inventory.item.stocked_at_store', $ctx, $item, ['sku' => $item->tenant_sku, 'store_id' => $store->id, 'source' => $d['source'] ?? 'manual']);

            $locationIds = $ctx->storeId === null ? null : InventoryLocation::query()->forTenant($ctx)->where('store_id', $ctx->storeId)->pluck('id');

            return $item->load(['variant.product', 'snapshots' => fn ($s) => $locationIds === null ? $s : $s->whereIn('location_id', $locationIds)]);
        });
    }

    /** Stock adjustment with a movement type, a reason, and an idempotency key supplied by the caller. */
    public function adjust(TenantContext $ctx, int $itemId, int $locationId, string $movement, int $delta, ?string $note): mixed
    {
        $ctx->can('inventory.adjust') || throw ApiException::forbidden();

        return DB::transaction(function () use ($ctx, $itemId, $locationId, $movement, $delta, $note) {
            $item = InventoryItem::query()->forTenant($ctx)->lockForUpdate()->find($itemId) ?? throw ApiException::notFound('Item');
            $loc = InventoryLocation::query()->forTenant($ctx)->find($locationId) ?? throw ApiException::notFound('Location');
            $ctx->assertStore($loc->store_id);

            $entry = $this->stock->adjust($ctx->tenantId, $ctx->userId, $item->id, $loc->id, $movement, $delta, $note, 'inventory_item', $item->id);
            $this->audit->record('inventory.stock.adjusted', $ctx, $item, ['movement' => $movement, 'delta' => $delta, 'note' => $note]);

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $d  location_id, supplier_id?, reference?, notes?, items[{inventory_item_id, quantity, unit_cost_cents}]
     */
    public function receivePurchase(TenantContext $ctx, array $d): PurchaseReceipt
    {
        $ctx->can('purchase.receive') || throw ApiException::forbidden();

        return DB::transaction(function () use ($ctx, $d) {
            $loc = InventoryLocation::query()->forTenant($ctx)->find($d['location_id']) ?? throw ApiException::notFound('Location');
            $ctx->assertStore($loc->store_id);

            $receipt = PurchaseReceipt::create([
                'tenant_id' => $ctx->tenantId, 'supplier_id' => $d['supplier_id'] ?? null, 'location_id' => $loc->id, 'reference' => $d['reference'] ?? null,
                'received_by' => $ctx->userId, 'received_at' => now(), 'notes' => $d['notes'] ?? null,
                'total_cost_cents' => collect($d['items'])->sum(fn ($i) => $i['quantity'] * $i['unit_cost_cents']),
            ]);

            foreach (collect($d['items'])->sortBy('inventory_item_id') as $line) {
                $item = InventoryItem::query()->forTenant($ctx)->lockForUpdate()->find($line['inventory_item_id']) ?? throw ApiException::notFound('Item');
                PurchaseReceiptItem::create(['tenant_id' => $ctx->tenantId, 'purchase_receipt_id' => $receipt->id, 'inventory_item_id' => $item->id, 'quantity' => $line['quantity'], 'unit_cost_cents' => $line['unit_cost_cents']]);
                $this->stock->adjust($ctx->tenantId, $ctx->userId, $item->id, $loc->id, 'purchase_receipt', $line['quantity'], null, 'purchase_receipt', $receipt->id);
                $item->update(['cost_cents' => $line['unit_cost_cents'], 'row_version' => $item->row_version + 1]);
            }
            $this->audit->record('inventory.purchase.received', $ctx, $receipt, ['total_cents' => $receipt->total_cost_cents]);

            return $receipt->load('items');
        });
    }

    /**
     * Update a listing's commercial fields. The client states the row_version it edited; a stale edit is rejected, never overwritten.
     *
     * @param  array<string, mixed>  $d
     */
    public function updateListing(TenantContext $ctx, int $listingId, array $d): TenantListing
    {
        $ctx->can('listings.manage') || throw ApiException::forbidden();
        if ((array_key_exists('price_cents', $d) || array_key_exists('sale_price_cents', $d)) && ! $ctx->can('prices.manage')) {
            throw ApiException::forbidden('Your role cannot change prices.');
        }

        return DB::transaction(function () use ($ctx, $listingId, $d) {
            $listing = TenantListing::query()->forTenant($ctx)->lockForUpdate()->find($listingId) ?? throw ApiException::notFound('Listing');
            $ctx->assertStore($listing->store_id);

            if (isset($d['expected_row_version']) && (int) $d['expected_row_version'] !== $listing->row_version) {
                throw ApiException::conflict('version_conflict', 'This listing changed since you opened it. Review the latest values.', ['current_row_version' => $listing->row_version]);
            }
            unset($d['expected_row_version']);

            $publish = $d['publish'] ?? null;
            unset($d['publish']);
            $listing->fill($d);
            if ($publish && ($listing->variant->status !== 'approved' || $listing->product->status !== 'approved')) {
                throw ApiException::conflict('product_not_approved', 'This product is waiting for catalog review. You can publish it once it is approved.');
            }
            if ($publish !== null) {
                $listing->visibility = $publish ? 'public' : 'hidden';
                $listing->publication_status = $publish ? 'published' : 'unpublished';
            }
            if ($listing->sale_price_cents !== null && $listing->sale_price_cents >= $listing->price_cents) {
                throw ApiException::invalid('invalid_sale_price', 'The sale price must be lower than the regular price.');
            }
            $listing->row_version++;
            $listing->save();

            $this->sync->listing($ctx->tenantId, $listing->id);
            $this->audit->record('listing.updated', $ctx, $listing, array_keys($d));

            return $listing;
        });
    }
}
