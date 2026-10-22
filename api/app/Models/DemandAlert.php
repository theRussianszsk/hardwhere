<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandAlert extends DomainModel
{
    public const UPDATED_AT = null;

    protected $table = 'demand_alerts';

    protected function casts(): array
    {
        return [
            'unserved' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(DemandAlertDelivery::class, 'demand_alert_id');
    }
}
