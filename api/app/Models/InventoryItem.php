<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends DomainModel
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_items';

    protected function casts(): array
    {
        return [
            'track_stock' => 'boolean',
            'cost_cents' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(StockSnapshot::class, 'inventory_item_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(TenantListing::class, 'inventory_item_id');
    }

    public function mapping(): HasOne
    {
        return $this->hasOne(TenantProductMapping::class, 'inventory_item_id');
    }
}
