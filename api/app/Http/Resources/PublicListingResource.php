<?php

namespace App\Http\Resources;

use App\Services\MarketplaceSearch;
use Illuminate\Http\Resources\Json\JsonResource;

/** Customer view of a tenant listing: selling price and an availability bucket. Never cost, margin, supplier, or exact private stock. */
class PublicListingResource extends JsonResource
{
    public function toArray($request): array
    {
        $available = (int) ($this->resource->marketplace_available ?? 0);

        return [
            'id' => $this->id, 'product_id' => $this->product_id, 'variant_id' => $this->variant_id,
            'title' => $this->title_override ?? $this->variant?->name, 'description' => $this->description, 'sku' => $this->tenant_sku,
            'price_cents' => $this->price_cents, 'sale_price_cents' => $this->sale_price_cents, 'currency' => $this->currency, 'uom' => $this->uom,
            'availability' => MarketplaceSearch::availability($available, $this->show_exact_stock),
            'pickup_available' => $this->pickup_available, 'delivery_available' => $this->delivery_available,
            'min_order_qty' => $this->min_order_qty, 'max_order_qty' => $this->max_order_qty, 'lead_time_hours' => $this->lead_time_hours,
            'store' => new PublicStoreResource($this->whenLoaded('store')),
            'variant' => new VariantResource($this->whenLoaded('variant')),
            'distance_km' => $this->when($this->resource->getAttribute('distance_km') !== null, fn () => $this->resource->getAttribute('distance_km')),
            'row_version' => $this->row_version, 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
