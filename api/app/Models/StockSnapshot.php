<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $inventory_item_id
 * @property int $location_id
 * @property int $on_hand
 * @property int $reserved
 * @property int $version
 * @property string $updated_at
 * @property-read InventoryLocation|null $location
 * @property-read InventoryItem|null $inventoryItem
 */
class StockSnapshot extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'stock_snapshots';

    public $timestamps = false;

    /** @return BelongsTo<InventoryLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
