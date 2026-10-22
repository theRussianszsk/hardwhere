<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property string|null $model_number
 * @property string|null $part_number
 * @property array<string, mixed> $attributes
 * @property string $uom
 * @property int $pack_quantity
 * @property string $variant_key
 * @property bool $is_default
 * @property string $status
 * @property int $row_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Product|null $product
 * @property-read Collection<int, ProductBarcode> $barcodes
 * @property-read Collection<int, ProductAlias> $aliases
 * @property-read Collection<int, ProductAttribute> $attributeRows
 */
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return HasMany<ProductBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'variant_id');
    }

    /** @return HasMany<ProductAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class, 'variant_id');
    }

    /** @return HasMany<ProductAttribute, $this> */
    public function attributeRows(): HasMany
    {
        return $this->hasMany(ProductAttribute::class, 'variant_id');
    }
}
