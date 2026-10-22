<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceCart extends DomainModel
{
    protected $table = 'marketplace_carts';

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceCartItem::class, 'cart_id');
    }
}
