<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends DomainModel
{
    use SoftDeletes;

    protected $table = 'product_variants';

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'pack_quantity' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'variant_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class, 'variant_id');
    }

    public function attributeRows(): HasMany
    {
        return $this->hasMany(ProductAttribute::class, 'variant_id');
    }
}
