<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $inventory_item_id
 * @property int $variant_id
 * @property string $source
 * @property int|null $ai_analysis_id
 * @property float|null $confidence
 * @property string|null $supplier_sku
 * @property int $confirmed_by
 * @property Carbon $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InventoryItem|null $inventoryItem
 */
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

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
