<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Reservation;
use App\Models\ReservationLine;
use App\Models\StockSnapshot;
use App\Models\TenantListing;
use App\Models\User;
use App\Support\ApiException;
use Illuminate\Support\Collection;

/** Marketplace ordering. Every order atomically reserves stock; there is no "soft" order that holds nothing. */
class OrderService
{
    public function __construct(
        private StockService $stock,
        private Idempotency $idempotency,
        private ReservationService $reservations,
        private SyncRecorder $sync,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by PlaceOrderRequest
     * @return array{0: Order, 1: bool}
     */
    public function place(User $customer, array $data, string $idempotencyKey): array
    {
        return $this->idempotency->run(
            "u{$customer->id}", $idempotencyKey, 'order.place', Idempotency::hash($data), Order::class,
            fn () => $this->execute($customer, $data, $idempotencyKey),
        );
    }

    /** @param  array<string, mixed>  $data */
    private function execute(User $customer, array $data, string $idempotencyKey): Order
    {
        $lines = collect($data['items'])->groupBy('listing_id')->map(fn ($g) => [
            'listing_id' => (int) $g[0]['listing_id'], 'quantity' => $g->sum('quantity'),
            'expected_unit_price_cents' => $g[0]['expected_unit_price_cents'] ?? null,
        ]);

        $listings = $this->purchasable($lines->keys()->all())->with(['variant', 'store', 'inventoryItem'])->get()->keyBy('id');
        foreach ($lines->keys() as $id) {
            $listings->has($id) || throw ApiException::conflict('listing_unavailable', 'An item is no longer listed.', ['listing_id' => $id]);
        }

        $first = $listings->first();
        if ($listings->contains(fn (TenantListing $l) => $l->store_id !== $first->store_id)) {
            throw ApiException::invalid('mixed_stores', 'An order can only contain items from one store.');
        }
        $store = $first->store;
        $type = $data['fulfillment_type'];

        if ($type === 'delivery' && ! $store->supports_delivery) {
            throw ApiException::invalid('delivery_unsupported', 'This store does not deliver.');
        }
        if ($type !== 'delivery' && ! $store->supports_pickup) {
            throw ApiException::invalid('pickup_unsupported', 'This store does not offer pickup.');
        }

        foreach ($lines as $line) {
            $l = $listings[$line['listing_id']];
            $unit = $l->sale_price_cents ?? $l->price_cents;
            ($type === 'delivery' ? $l->delivery_available : $l->pickup_available)
                || throw ApiException::invalid('fulfillment_unsupported', "{$l->variant->name} is not available for $type.");
            if ($line['quantity'] < $l->min_order_qty || ($l->max_order_qty && $line['quantity'] > $l->max_order_qty)) {
                throw ApiException::invalid('quantity_out_of_range', 'Quantity is outside the allowed range for an item.', [
                    'listing_id' => $l->id, 'min' => $l->min_order_qty, 'max' => $l->max_order_qty,
                ]);
            }
            if ($line['expected_unit_price_cents'] !== null && (int) $line['expected_unit_price_cents'] !== $unit) {
                throw ApiException::conflict('price_changed', 'A price changed. Review your cart.', [
                    'listing_id' => $l->id, 'expected' => (int) $line['expected_unit_price_cents'], 'current' => $unit,
                ]);
            }
        }

        $lines = $lines->sortBy(fn ($l) => $listings[$l['listing_id']]->inventory_item_id);
        $itemIds = $lines->map(fn ($l) => $listings[$l['listing_id']]->inventory_item_id)->all();
        $this->reservations->expireDueForItems($store->tenant_id, $itemIds);
        $locked = $this->stock->lockStoreStock($store->tenant_id, $store->id, $itemIds);

        $subtotal = $lines->sum(fn ($l) => ($listings[$l['listing_id']]->sale_price_cents ?? $listings[$l['listing_id']]->price_cents) * $l['quantity']);
        $tenant = $store->tenant;
        $order = Order::create([
            'tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'customer_id' => $customer->id, 'status' => OrderStatus::Pending->value,
            'fulfillment_type' => $type, 'subtotal_cents' => $subtotal, 'delivery_fee_cents' => 0, 'total_cents' => $subtotal,
            'contact_name' => $data['contact_name'], 'contact_phone' => $data['contact_phone'],
            'delivery_address' => $data['delivery_address'] ?? null, 'notes' => $data['notes'] ?? null,
            'idempotency_key' => $idempotencyKey, 'placed_at' => now(),
        ]);
        $order->update(['order_no' => 'HW-'.strtoupper(str_pad(base_convert((string) $order->id, 10, 36), 6, '0', STR_PAD_LEFT))]);

        $reservation = Reservation::create([
            'tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'status' => 'active',
            'expires_at' => now()->addMinutes($tenant->reservation_ttl_minutes),
        ]);

        foreach ($lines as $line) {
            $l = $listings[$line['listing_id']];
            $unit = $l->sale_price_cents ?? $l->price_cents;
            $item = OrderItem::create([
                'tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'listing_id' => $l->id, 'inventory_item_id' => $l->inventory_item_id,
                'variant_id' => $l->variant_id, 'sku' => $l->tenant_sku, 'name' => $l->title_override ?? $l->variant->name,
                'quantity' => $line['quantity'], 'unit_price_cents' => $unit, 'line_total_cents' => $unit * $line['quantity'],
            ]);

            foreach ($this->allocate($locked, $l, $line['quantity']) as ['snapshot' => $snap, 'quantity' => $take]) {
                $this->stock->move($snap, 0, $take, 'reservation', $customer->id, 'order', $order->id, null, $l->id);
                ReservationLine::create([
                    'tenant_id' => $order->tenant_id, 'reservation_id' => $reservation->id, 'order_item_id' => $item->id,
                    'inventory_item_id' => $l->inventory_item_id, 'location_id' => $snap->location_id, 'quantity' => $take,
                ]);
            }
            $this->stock->touchItem($order->tenant_id, $l->inventory_item_id);
        }

        OrderStatus::Pending->canMoveTo(OrderStatus::Reserved) && $order->update(['status' => OrderStatus::Reserved->value]);
        $this->sync->userEntity($customer->id, 'order', $order->id);
        $this->sync->tenantEntity($order->tenant_id, 'order', $order->id);
        $this->audit->record('order.placed', $order->tenant_id, $order, ['total_cents' => $subtotal], $customer->id);

        return $order->load('items', 'reservation');
    }

    /**
     * StockService::allocate, with a customer-safe 409: the listing instead of the internal item id, and the exact
     * available count only when the store shows exact stock (otherwise just the public availability bucket).
     *
     * @param  Collection<int, StockSnapshot>  $locked
     * @return list<array{snapshot: StockSnapshot, quantity: int}>
     */
    private function allocate(Collection $locked, TenantListing $l, int $quantity): array
    {
        try {
            return $this->stock->allocate($locked, $l->inventory_item_id, $quantity, $l->stock_buffer);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'stock_unavailable') {
                throw $e;
            }
            $available = (int) $e->details['available'];
            $public = MarketplaceSearch::availability($available, $l->show_exact_stock);
            throw ApiException::conflict('stock_unavailable', $e->getMessage(), ['listing_id' => $l->id, 'requested' => $quantity, 'availability' => $public['status']]
                + ($l->show_exact_stock ? ['available' => $available] : []));
        }
    }

    /** Listings a customer may buy: exactly what the marketplace shows (published, public, approved product, live store, active tenant). @param list<int> $ids */
    public function purchasable(array $ids)
    {
        return MarketplaceSearch::liveListings()->whereIn('tenant_listings.id', $ids);
    }

    public function vendorTransition(int $orderId, OrderStatus $to, ?string $reason, int $actorId): Order
    {
        return $this->reservations->transition($orderId, $to, $actorId, $reason);
    }

    public function customerCancel(User $customer, int $orderId, ?string $reason): Order
    {
        Order::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->find($orderId) ?? throw ApiException::notFound('Order');

        return $this->reservations->transition($orderId, OrderStatus::Cancelled, $customer->id, $reason, customerInitiated: true);
    }
}
