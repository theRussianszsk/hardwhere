<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class VariantResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'product_id' => $this->product_id, 'name' => $this->name, 'model_number' => $this->model_number, 'part_number' => $this->part_number,
            'attributes' => $this->attributes ?: (object) [], 'uom' => $this->uom, 'pack_quantity' => $this->pack_quantity, 'is_default' => $this->is_default,
            'status' => $this->status, 'barcodes' => $this->whenLoaded('barcodes', fn () => $this->barcodes->map(fn ($b) => ['code' => $b->code, 'type' => $b->type])->values()),
        ];
    }
}
