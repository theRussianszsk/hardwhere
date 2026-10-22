<?php

namespace App\Http\Resources;

use App\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $ctx = TenantContext::current();
        $snaps = $this->whenLoaded('snapshots');
        $onHand = $this->relationLoaded('snapshots') ? $this->snapshots->sum('on_hand') : null;
        $reserved = $this->relationLoaded('snapshots') ? $this->snapshots->sum('reserved') : null;

        return [
            'id' => $this->id, 'variant_id' => $this->variant_id, 'sku' => $this->tenant_sku, 'name' => $this->whenLoaded('variant', fn () => $this->variant->name),
            'brand' => $this->whenLoaded('variant', fn () => $this->variant->product?->brand), 'product_name' => $this->whenLoaded('variant', fn () => $this->variant->product?->name),
            'barcodes' => $this->whenLoaded('variant', fn () => $this->variant->barcodes->pluck('code')->values()),
            'reorder_threshold' => $this->reorder_threshold, 'track_stock' => $this->track_stock, 'default_location_id' => $this->default_location_id,
            'on_hand' => $onHand, 'reserved' => $reserved, 'available' => $onHand === null ? null : $onHand - $reserved,
            'low_stock' => $onHand === null ? null : ($onHand - $reserved) <= $this->reorder_threshold,
            'locations' => $this->when($this->relationLoaded('snapshots'), fn () => $this->snapshots->map(fn ($s) => ['location_id' => $s->location_id, 'on_hand' => $s->on_hand, 'reserved' => $s->reserved])->values()),
            // Margin inputs are only visible to roles that manage inventory costs.
            'cost_cents' => $this->when($ctx?->can('inventory.cost.view'), fn () => $this->cost_cents),
            'supplier_id' => $this->when($ctx?->can('inventory.cost.view'), fn () => $this->supplier_id),
            'row_version' => $this->row_version, 'updated_at' => $this->updated_at?->toIso8601String(), 'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
