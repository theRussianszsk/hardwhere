<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MarketplaceCartItem> $items
 */
class MarketplaceCart extends DomainModel
{
    protected $table = 'marketplace_carts';

    /** @return HasMany<MarketplaceCartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceCartItem::class, 'cart_id');
    }
}
