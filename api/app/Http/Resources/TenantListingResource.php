<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TenantListingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'store_id' => $this->store_id, 'inventory_item_id' => $this->inventory_item_id, 'product_id' => $this->product_id, 'variant_id' => $this->variant_id,
            'sku' => $this->tenant_sku, 'title' => $this->title_override ?? $this->variant?->name, 'title_override' => $this->title_override, 'description' => $this->description,
            'price_cents' => $this->price_cents, 'sale_price_cents' => $this->sale_price_cents, 'currency' => $this->currency, 'uom' => $this->uom,
            'visibility' => $this->visibility, 'publication_status' => $this->publication_status, 'pickup_available' => $this->pickup_available,
            'delivery_available' => $this->delivery_available, 'min_order_qty' => $this->min_order_qty, 'max_order_qty' => $this->max_order_qty,
            'lead_time_hours' => $this->lead_time_hours, 'stock_buffer' => $this->stock_buffer, 'show_exact_stock' => $this->show_exact_stock,
            // Cached on devices as "last known": never presented as live or confirmed.
            'available_quantity' => $this->when(isset($this->resource->pos_available), fn () => (int) $this->resource->pos_available),
            'variant' => new VariantResource($this->whenLoaded('variant')),
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'brand' => $this->product->brand, 'category' => $this->product->category?->name]),
            'row_version' => $this->row_version, 'updated_at' => $this->updated_at?->toIso8601String(), 'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
