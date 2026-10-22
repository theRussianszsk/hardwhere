<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'parent_id' => $this->parent_id, 'slug' => $this->slug, 'name' => $this->name, 'sort' => $this->sort, 'updated_at' => $this->updated_at?->toIso8601String()];
    }
}
