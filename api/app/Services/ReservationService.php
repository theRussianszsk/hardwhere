<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\ReservationLine;
use App\Models\StockSnapshot;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Order state machine side effects on stock. Lock order is always: order row -> reservation -> stock rows (item, location),
 * everywhere, so checkout, vendor transitions, customer cancellation and the expiry job cannot deadlock each other.
 */
class ReservationService
{
    /** Statuses whose reservation may lapse on its own. 'preparing' is owned by the store: it completes or cancels it. */
    private const EXPIRABLE = ['reserved', 'confirmed', 'ready_for_pickup'];

    public function __construct(private StockService $stock, private SyncRecorder $sync, private AuditLogger $audit) {}

    /** Move an order along the state machine, applying the stock effect that goes with the new state. */
    public function transition(int $orderId, OrderStatus $to, ?int $actorUserId, ?string $reason = null, bool $customerInitiated = false): Order
    {
        return DB::transaction(function () use ($orderId, $to, $actorUserId, $reason, $customerInitiated) {
            $order = Order::query()->withoutGlobalScopes()->lockForUpdate()->find($orderId) ?? throw ApiException::notFound('Order');
            $from = OrderStatus::from($order->status);

            if (! $from->canMoveTo($to)) {
                throw ApiException::conflict('invalid_transition', "An order cannot go from {$from->value} to {$to->value}.", ['from' => $from->value, 'to' => $to->value]);
            }
            if ($customerInitiated && ! ($to === OrderStatus::Cancelled && $from->customerCancellable())) {
                throw ApiException::conflict('not_cancellable', 'This order can no longer be cancelled online. Please contact the store.');
            }

            $reservation = Reservation::query()->withoutGlobalScopes()->where('order_id', $order->id)->lockForUpdate()->first();
            $attrs = ['status' => $to->value, 'row_version' => $order->row_version + 1];

            if ($reservation && $reservation->status === 'active') {
                match (true) {
                    $to === OrderStatus::Fulfilled => $this->settle($reservation, $order, 'order_fulfillment', -1, 'converted', $actorUserId, $reason),
                    $to === OrderStatus::Cancelled => $this->settle($reservation, $order, 'reservation_release', 0, 'cancelled', $actorUserId, $reason),
                    $to === OrderStatus::Expired => $this->settle($reservation, $order, 'reservation_release', 0, 'expired', null, 'Reservation expired'),
                    default => null,
                };
                // A store that confirmed or readied the order has committed to it: the customer gets a pickup window from now,
                // not whatever is left of the initial hold (which could lapse minutes after the item was bagged).
                if (in_array($to, [OrderStatus::Confirmed, OrderStatus::ReadyForPickup], true)) {
                    $hold = now()->addMinutes((int) config('hardwhere.marketplace.pickup_hold_minutes'));
                    $hold->greaterThan($reservation->expires_at) && $reservation->update(['expires_at' => $hold]);
                }
            }

            $attrs += match ($to) {
                OrderStatus::Confirmed => ['confirmed_at' => now()],
                OrderStatus::ReadyForPickup => ['ready_at' => now()],
                OrderStatus::Fulfilled => ['fulfilled_at' => now()],
                OrderStatus::Cancelled, OrderStatus::Expired => ['cancelled_at' => now(), 'cancel_reason' => $reason],
                default => [],
            };
            $order->update($attrs);

            $this->sync->userEntity($order->customer_id, 'order', $order->id);
            $this->sync->tenantEntity($order->tenant_id, 'order', $order->id);
            $this->audit->record("order.{$to->value}", $order->tenant_id, $order, ['from' => $from->value, 'reason' => $reason], $actorUserId);

            return $order;
        }, 3);
    }

    /**
     * Release or convert a reservation's stock holds.
     *
     * @param  int  $onHandSign  -1 consumes the units (fulfilment), 0 only releases the hold
     */
    private function settle(Reservation $reservation, Order $order, string $movement, int $onHandSign, string $status, ?int $actorUserId, ?string $note): void
    {
        $lines = ReservationLine::query()->withoutGlobalScopes()->where('reservation_id', $reservation->id)->orderBy('inventory_item_id')->orderBy('location_id')->get();

        foreach ($lines as $line) {
            $snap = StockSnapshot::query()->withoutGlobalScopes()->where('tenant_id', $order->tenant_id)
                ->where('inventory_item_id', $line->inventory_item_id)->where('location_id', $line->location_id)->lockForUpdate()->firstOrFail();
            $this->stock->move($snap, $onHandSign * $line->quantity, -$line->quantity, $movement, $actorUserId, 'order', $order->id, $note);
            $this->stock->touchItem($order->tenant_id, $line->inventory_item_id);
        }

        $reservation->update(['status' => $status, 'released_at' => now()]);
    }

    /** Expire every reservation past its deadline. Safe to run concurrently; each order is locked and re-checked. */
    public function expireDue(int $limit = 200): int
    {
        $ids = Reservation::query()->withoutGlobalScopes()->where('status', 'active')->where('expires_at', '<=', now())
            ->whereIn('order_id', Order::query()->withoutGlobalScopes()->whereIn('status', self::EXPIRABLE)->select('id'))
            ->orderBy('expires_at')->limit($limit)->pluck('order_id');

        return $ids->filter(fn ($orderId) => $this->tryExpire($orderId))->count();
    }

    /**
     * Called inside checkout transactions: reservations that are overdue must not keep blocking stock for the item.
     *
     * @param  list<int>  $itemIds
     */
    public function expireDueForItems(int $tenantId, array $itemIds): void
    {
        Reservation::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'active')->where('expires_at', '<=', now())
            ->whereIn('order_id', Order::query()->withoutGlobalScopes()->whereIn('status', self::EXPIRABLE)->select('id'))
            ->whereIn('id', fn ($q) => $q->select('reservation_id')->from('reservation_lines')->whereIn('inventory_item_id', $itemIds))
            ->orderBy('expires_at')->limit(50)->pluck('order_id')->each(fn ($orderId) => $this->tryExpire($orderId));
    }

    private function tryExpire(int $orderId): bool
    {
        $order = Order::query()->withoutGlobalScopes()->find($orderId);
        $status = $order ? OrderStatus::from($order->status) : null;

        // Preparing orders are owned by the store: it can cancel or complete them; they do not auto-expire.
        if (! $status || ! $status->canMoveTo(OrderStatus::Expired)) {
            return false;
        }

        try {
            $this->transition($orderId, OrderStatus::Expired, null);

            return true;
        } catch (ApiException) {
            return false; // someone else moved the order first
        }
    }
}
