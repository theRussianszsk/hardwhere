<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends DomainModel
{
    protected $table = 'product_attributes';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'value_number' => 'float',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
