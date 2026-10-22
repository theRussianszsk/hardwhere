<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property int $listing_id
 * @property int $inventory_item_id
 * @property int $variant_id
 * @property string $sku
 * @property string $name
 * @property int $quantity
 * @property int $unit_price_cents
 * @property int $line_total_cents
 * @property-read Order|null $order
 */
class OrderItem extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'order_items';

    public $timestamps = false;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
