<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $sale_id
 * @property int $listing_id
 * @property int $inventory_item_id
 * @property int $variant_id
 * @property string $sku
 * @property string $name
 * @property int $quantity
 * @property int $unit_price_cents
 * @property int $discount_cents
 * @property int $vat_cents
 * @property int $line_total_cents
 * @property int $unit_cost_cents
 * @property int $returned_quantity
 * @property-read Sale|null $sale
 */
class SaleItem extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'sale_items';

    public $timestamps = false;

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
