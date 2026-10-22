<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\PosSession;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockSnapshot;
use App\Models\Store;
use App\Models\TenantListing;
use App\Support\ApiException;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Online, server-authoritative POS checkout. A SQLite draft or queued command is never a sale. */
class PosService
{
    public function __construct(
        private StockService $stock,
        private Idempotency $idempotency,
        private ReservationService $reservations,
        private SyncRecorder $sync,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by CheckoutRequest
     * @return array{0: Sale, 1: bool}
     */
    public function checkout(TenantContext $ctx, array $data, string $idempotencyKey): array
    {
        $ctx->can('pos.sell') || throw ApiException::forbidden();
        $storeId = (int) ($data['store_id'] ?? $ctx->storeId);
        $ctx->assertStore($storeId);

        $hasDiscount = collect($data['items'])->sum(fn ($l) => (int) ($l['discount_cents'] ?? 0)) > 0;
        if ($hasDiscount && ! $ctx->can('pos.discount')) {
            throw ApiException::forbidden('Your role cannot apply discounts.');
        }

        return $this->idempotency->run(
            "t{$ctx->tenantId}:u{$ctx->userId}", $idempotencyKey, 'pos.checkout', Idempotency::hash($data + ['_store' => $storeId]), Sale::class,
            fn () => $this->execute($ctx, $storeId, $data, $idempotencyKey),
        );
    }

    /** @param  array<string, mixed>  $data */
    private function execute(TenantContext $ctx, int $storeId, array $data, string $idempotencyKey): Sale
    {
        $store = Store::query()->forTenant($ctx)->where('is_active', true)->find($storeId) ?? throw ApiException::notFound('Store');

        // Merge duplicate lines, then process in a deterministic order.
        $lines = collect($data['items'])->groupBy('listing_id')->map(fn ($g) => [
            'listing_id' => (int) $g[0]['listing_id'],
            'quantity' => $g->sum('quantity'),
            'discount_cents' => $g->sum(fn ($l) => (int) ($l['discount_cents'] ?? 0)),
            'expected_unit_price_cents' => $g[0]['expected_unit_price_cents'] ?? null,
        ]);

        $listings = TenantListing::query()->forTenant($ctx)->with(['variant', 'inventoryItem'])
            ->where('store_id', $store->id)->whereIn('id', $lines->keys())->get()->keyBy('id');
        $lines = $lines->sortBy(fn ($l) => $listings[$l['listing_id']]->inventory_item_id ?? 0);

        foreach ($lines as $line) {
            $listing = $listings[$line['listing_id']] ?? throw ApiException::invalid('listing_not_found', 'An item is not sold at this store.', ['listing_id' => $line['listing_id']]);
            $unit = $listing->sale_price_cents ?? $listing->price_cents;
            if ($line['expected_unit_price_cents'] !== null && (int) $line['expected_unit_price_cents'] !== $unit) {
                throw ApiException::conflict('price_changed', 'A price changed. Refresh and review the cart.', [
                    'listing_id' => $listing->id, 'expected' => (int) $line['expected_unit_price_cents'], 'current' => $unit,
                ]);
            }
            if ($line['discount_cents'] > $unit * $line['quantity']) {
                throw ApiException::invalid('discount_too_large', 'A discount exceeds the line amount.', ['listing_id' => $listing->id]);
            }
        }

        $itemIds = $lines->map(fn ($l) => $listings[$l['listing_id']]->inventory_item_id)->all();
        $this->reservations->expireDueForItems($ctx->tenantId, $itemIds);
        $locked = $this->stock->lockStoreStock($ctx->tenantId, $store->id, $itemIds);

        $pay = $data['payment'];
        $built = $lines->map(function ($line) use ($listings, $ctx) {
            $listing = $listings[$line['listing_id']];
            $unit = $listing->sale_price_cents ?? $listing->price_cents;
            $gross = $unit * $line['quantity'];
            $lineTotal = $gross - $line['discount_cents'];

            return [$listing, $line, $unit, $gross, $lineTotal, Vat::includedIn($lineTotal, $ctx->vatRateBps)];
        });
        $subtotal = $built->sum(fn ($b) => $b[3]);
        $discount = $built->sum(fn ($b) => $b[1]['discount_cents']);
        $total = $subtotal - $discount;

        if ($pay['method'] === 'cash') {
            $tendered = (int) ($pay['tendered_cents'] ?? 0);
            $tendered >= $total || throw ApiException::invalid('insufficient_tender', 'Cash tendered is less than the total.', ['total_cents' => $total]);
        } else {
            $tendered = null;
        }

        $session = null;
        if (! empty($data['pos_session_id'])) {
            $session = PosSession::query()->forTenant($ctx)->whereNull('closed_at')->with('register')->find($data['pos_session_id'])
                ?? throw ApiException::invalid('session_closed', 'The register session is not open.');
            ($session->register->store_id === $store->id && ($session->opened_by === $ctx->userId || $ctx->can('sales.view_all')))
                || throw ApiException::invalid('session_invalid', 'That register session belongs to another store or cashier.');
        }

        $sale = Sale::create([
            'tenant_id' => $ctx->tenantId, 'store_id' => $store->id, 'pos_session_id' => $session?->id, 'cashier_id' => $ctx->userId,
            'status' => 'completed', 'subtotal_cents' => $subtotal, 'discount_cents' => $discount,
            'vat_cents' => $built->sum(fn ($b) => $b[5]), 'total_cents' => $total,
            'payment_method' => $pay['method'], 'payment_reference' => $pay['reference'] ?? null,
            'tendered_cents' => $tendered, 'change_cents' => $tendered !== null ? $tendered - $total : 0,
            'customer_name' => $data['customer']['name'] ?? null, 'customer_phone' => $data['customer']['phone'] ?? null,
            'idempotency_key' => $idempotencyKey, 'completed_at' => now(),
        ]);
        $sale->update(['receipt_no' => sprintf('%s-%s-%06d', $store->code, now('Asia/Manila')->format('ymd'), $sale->id)]);

        foreach ($built as [$listing, $line, $unit, $gross, $lineTotal, $vat]) {
            SaleItem::create([
                'tenant_id' => $ctx->tenantId, 'sale_id' => $sale->id, 'listing_id' => $listing->id, 'inventory_item_id' => $listing->inventory_item_id,
                'variant_id' => $listing->variant_id, 'sku' => $listing->tenant_sku, 'name' => $listing->title_override ?? $listing->variant->name,
                'quantity' => $line['quantity'], 'unit_price_cents' => $unit, 'discount_cents' => $line['discount_cents'], 'vat_cents' => $vat,
                'line_total_cents' => $lineTotal, 'unit_cost_cents' => $listing->inventoryItem->cost_cents,
            ]);

            foreach ($this->stock->allocate($locked, $listing->inventory_item_id, $line['quantity']) as ['snapshot' => $snap, 'quantity' => $take]) {
                $this->stock->move($snap, -$take, 0, 'sale', $ctx->userId, 'sale', $sale->id, null, $listing->id);
            }
            $this->stock->touchItem($ctx->tenantId, $listing->inventory_item_id);
        }

        $this->sync->tenantEntity($ctx->tenantId, 'sale', $sale->id);
        $this->audit->record('pos.sale.completed', $ctx, $sale, ['total_cents' => $total, 'method' => $pay['method']]);

        return $sale->load('items');
    }

    public function void(TenantContext $ctx, int $saleId, string $reason): Sale
    {
        $ctx->can('sales.void') || throw ApiException::forbidden();

        return DB::transaction(function () use ($ctx, $saleId, $reason) {
            $sale = Sale::query()->forTenant($ctx)->lockForUpdate()->find($saleId) ?? throw ApiException::notFound('Sale');
            $ctx->assertStore($sale->store_id);
            $sale->status === 'completed' || throw ApiException::conflict('already_voided', 'This sale was already voided.');

            $entries = DB::table('stock_ledger_entries')->where('tenant_id', $ctx->tenantId)->where('reference_type', 'sale')
                ->where('reference_id', $sale->id)->where('movement_type', 'sale')->orderBy('inventory_item_id')->orderBy('location_id')->get();

            foreach ($entries as $e) {
                $snap = StockSnapshot::query()->forTenant($ctx)->where('inventory_item_id', $e->inventory_item_id)
                    ->where('location_id', $e->location_id)->lockForUpdate()->firstOrFail();
                $this->stock->move($snap, -$e->quantity_delta, 0, 'return', $ctx->userId, 'sale', $sale->id, "Void: $reason", $e->listing_id);
                $this->stock->touchItem($ctx->tenantId, $e->inventory_item_id);
            }

            $sale->items()->update(['returned_quantity' => DB::raw('quantity')]);
            $sale->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $ctx->userId, 'void_reason' => $reason]);
            $this->sync->tenantEntity($ctx->tenantId, 'sale', $sale->id);
            $this->audit->record('pos.sale.voided', $ctx, $sale, ['reason' => $reason]);

            return $sale->load('items');
        });
    }

    /** Items at the store that are at or below their reorder threshold after a sale. @param list<int> $itemIds @return list<array<string, mixed>> */
    public function lowStock(TenantContext $ctx, int $storeId, array $itemIds): array
    {
        return InventoryItem::query()->forTenant($ctx)->whereIn('id', $itemIds)->get()->map(function (InventoryItem $item) use ($ctx, $storeId) {
            $remaining = (int) DB::table('stock_snapshots as ss')->join('inventory_locations as il', 'il.id', '=', 'ss.location_id')
                ->where('ss.tenant_id', $ctx->tenantId)->where('ss.inventory_item_id', $item->id)->where('il.store_id', $storeId)
                ->selectRaw('COALESCE(SUM(ss.on_hand - ss.reserved), 0) AS q')->value('q');

            return ['inventory_item_id' => $item->id, 'sku' => $item->tenant_sku, 'remaining' => $remaining, 'reorder_threshold' => $item->reorder_threshold];
        })->filter(fn ($r) => $r['remaining'] <= $r['reorder_threshold'])->values()->all();
    }
}
