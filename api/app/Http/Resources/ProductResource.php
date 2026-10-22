<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** Canonical product. Contains no tenant-private data; safe for the customer cache. */
class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'slug' => $this->category->slug, 'name' => $this->category->name]),
            'category_id' => $this->category_id, 'brand' => $this->brand, 'manufacturer' => $this->manufacturer, 'name' => $this->name, 'model_number' => $this->model_number,
            'product_type' => $this->product_type, 'description' => $this->description, 'attributes' => $this->attributes ?: (object) [], 'compatibility' => $this->compatibility,
            'included_components' => $this->included_components, 'safety_info' => $this->safety_info, 'warranty' => $this->warranty, 'ph_specs' => $this->ph_specs ?: (object) [],
            'synonyms' => $this->synonyms, 'status' => $this->status,
            'images' => $this->whenLoaded('images', fn () => $this->images->whereNull('tenant_id')->map(fn ($i) => ['url' => $i->path, 'alt' => $i->alt, 'is_primary' => $i->is_primary])->values()),
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
            'min_price_cents' => $this->when(isset($this->resource->min_price_cents), fn () => (int) $this->resource->min_price_cents),
            'max_price_cents' => $this->when(isset($this->resource->max_price_cents), fn () => (int) $this->resource->max_price_cents),
            'store_count' => $this->when(isset($this->resource->store_count), fn () => (int) $this->resource->store_count),
            'relevance' => $this->when(isset($this->resource->relevance), fn () => round((float) $this->resource->relevance, 3)),
            'row_version' => $this->row_version, 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
