<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryLocation extends DomainModel
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_locations';

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
