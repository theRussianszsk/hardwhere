<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends DomainModel
{
    use SoftDeletes;

    protected $table = 'products';

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'compatibility' => 'array',
            'included_components' => 'array',
            'ph_specs' => 'array',
            'synonyms' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->orderBy('id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class, 'product_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }
}
