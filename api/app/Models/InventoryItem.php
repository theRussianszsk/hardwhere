<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $variant_id
 * @property string $tenant_sku
 * @property int $cost_cents
 * @property int|null $supplier_id
 * @property int $reorder_threshold
 * @property bool $track_stock
 * @property int|null $default_location_id
 * @property string|null $notes
 * @property int $row_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ProductVariant|null $variant
 * @property-read Supplier|null $supplier
 * @property-read Collection<int, StockSnapshot> $snapshots
 * @property-read Collection<int, TenantListing> $listings
 * @property-read TenantProductMapping|null $mapping
 */
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

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** @return HasMany<StockSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(StockSnapshot::class, 'inventory_item_id');
    }

    /** @return HasMany<TenantListing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(TenantListing::class, 'inventory_item_id');
    }

    /** @return HasOne<TenantProductMapping, $this> */
    public function mapping(): HasOne
    {
        return $this->hasOne(TenantProductMapping::class, 'inventory_item_id');
    }

    /**
     * Available stock at or below the reorder threshold. With a store, only that store's locations count: the same numbers a
     * store-bound user's item list flags as low (InventoryItemResource over store-filtered snapshots).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLowStock(Builder $query, ?int $storeId = null): Builder
    {
        $atStore = $storeId === null ? '' : ' AND ss.location_id IN (SELECT id FROM inventory_locations WHERE store_id = ?)';

        return $query->whereRaw("(SELECT COALESCE(SUM(ss.on_hand - ss.reserved), 0) FROM stock_snapshots ss WHERE ss.inventory_item_id = inventory_items.id$atStore) <= inventory_items.reorder_threshold",
            $storeId === null ? [] : [$storeId]);
    }
}
