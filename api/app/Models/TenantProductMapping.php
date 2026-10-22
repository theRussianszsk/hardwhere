<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantProductMapping extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'tenant_product_mappings';

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'confidence' => 'float',
        ];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
