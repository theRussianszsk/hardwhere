<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceCartItem extends DomainModel
{
    protected $table = 'marketplace_cart_items';

    public function listing(): BelongsTo
    {
        return $this->belongsTo(TenantListing::class, 'listing_id');
    }
}
