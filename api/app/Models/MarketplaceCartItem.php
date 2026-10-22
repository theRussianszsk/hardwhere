<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $listing_id
 * @property int $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TenantListing|null $listing
 */
class MarketplaceCartItem extends DomainModel
{
    protected $table = 'marketplace_cart_items';

    /** @return BelongsTo<TenantListing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(TenantListing::class, 'listing_id');
    }
}
