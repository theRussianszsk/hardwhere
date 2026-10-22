<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $inventory_item_id
 * @property int $product_id
 * @property int $variant_id
 * @property string $tenant_sku
 * @property string|null $title_override
 * @property string|null $description
 * @property int $price_cents
 * @property int|null $sale_price_cents
 * @property string $currency
 * @property string $uom
 * @property string $visibility
 * @property string $publication_status
 * @property bool $pickup_available
 * @property bool $delivery_available
 * @property int $min_order_qty
 * @property int|null $max_order_qty
 * @property int $lead_time_hours
 * @property int $stock_buffer
 * @property bool $show_exact_stock
 * @property int $row_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Store|null $store
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 * @property-read InventoryItem|null $inventoryItem
 */
class TenantListing extends DomainModel
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tenant_listings';

    protected function casts(): array
    {
        return [
            'pickup_available' => 'boolean',
            'delivery_available' => 'boolean',
            'show_exact_stock' => 'boolean',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
