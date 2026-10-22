<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends DomainModel
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'stores';

    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'supports_pickup' => 'boolean',
            'supports_delivery' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(InventoryLocation::class, 'store_id');
    }
}
