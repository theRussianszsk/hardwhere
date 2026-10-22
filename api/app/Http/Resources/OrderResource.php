<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $status = OrderStatus::from($this->status);

        return [
            'id' => $this->id, 'order_no' => $this->order_no, 'status' => $this->status, 'fulfillment_type' => $this->fulfillment_type,
            'store' => new PublicStoreResource($this->whenLoaded('store')), 'store_id' => $this->store_id,
            'subtotal_cents' => $this->subtotal_cents, 'delivery_fee_cents' => $this->delivery_fee_cents, 'total_cents' => $this->total_cents,
            'contact_name' => $this->contact_name, 'contact_phone' => $this->contact_phone, 'delivery_address' => $this->delivery_address, 'notes' => $this->notes,
            'placed_at' => $this->placed_at?->toIso8601String(), 'confirmed_at' => $this->confirmed_at?->toIso8601String(), 'ready_at' => $this->ready_at?->toIso8601String(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(), 'cancelled_at' => $this->cancelled_at?->toIso8601String(), 'cancel_reason' => $this->cancel_reason,
            'reservation' => $this->whenLoaded('reservation', fn () => $this->reservation ? ['status' => $this->reservation->status, 'expires_at' => $this->reservation->expires_at->toIso8601String()] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'listing_id' => $i->listing_id, 'variant_id' => $i->variant_id, 'sku' => $i->sku, 'name' => $i->name, 'quantity' => $i->quantity,
                'unit_price_cents' => $i->unit_price_cents, 'line_total_cents' => $i->line_total_cents,
            ])->values()),
            'customer_can_cancel' => $status->customerCancellable(),
            'next_statuses' => array_map(fn ($s) => $s->value, array_filter($status->next(), fn ($s) => ! in_array($s, [OrderStatus::Expired, OrderStatus::Failed, OrderStatus::Reserved], true))),
            'row_version' => $this->row_version, 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
