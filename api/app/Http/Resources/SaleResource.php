<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'receipt_no' => $this->receipt_no, 'store_id' => $this->store_id, 'status' => $this->status, 'cashier_id' => $this->cashier_id,
            'subtotal_cents' => $this->subtotal_cents, 'discount_cents' => $this->discount_cents, 'vat_cents' => $this->vat_cents, 'total_cents' => $this->total_cents,
            'payment_method' => $this->payment_method, 'payment_reference' => $this->payment_reference, 'tendered_cents' => $this->tendered_cents, 'change_cents' => $this->change_cents,
            'customer_name' => $this->customer_name, 'customer_phone' => $this->customer_phone, 'completed_at' => $this->completed_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(), 'void_reason' => $this->void_reason,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'listing_id' => $i->listing_id, 'sku' => $i->sku, 'name' => $i->name, 'quantity' => $i->quantity, 'unit_price_cents' => $i->unit_price_cents,
                'discount_cents' => $i->discount_cents, 'vat_cents' => $i->vat_cents, 'line_total_cents' => $i->line_total_cents, 'returned_quantity' => $i->returned_quantity,
            ])->values()),
            'low_stock' => $this->when($this->resource->getAttribute('low_stock') !== null, fn () => $this->resource->getAttribute('low_stock')),
        ];
    }
}
