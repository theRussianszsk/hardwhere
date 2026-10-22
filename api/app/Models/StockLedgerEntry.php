<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $inventory_item_id
 * @property int $location_id
 * @property int|null $listing_id
 * @property string $movement_type
 * @property int $quantity_delta
 * @property int $before_quantity
 * @property int $after_quantity
 * @property int $reserved_delta
 * @property int $before_reserved
 * @property int $after_reserved
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $note
 * @property Carbon $occurred_at
 * @property-read InventoryLocation|null $location
 */
class StockLedgerEntry extends DomainModel
{
    use AppendOnly, BelongsToTenant;

    protected $table = 'stock_ledger_entries';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InventoryLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }
}
