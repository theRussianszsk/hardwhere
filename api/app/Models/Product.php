<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $category_id
 * @property string|null $brand
 * @property string|null $manufacturer
 * @property string $name
 * @property string|null $model_number
 * @property string|null $product_type
 * @property string|null $description
 * @property array<string, mixed> $attributes
 * @property array<string, mixed> $compatibility
 * @property array<string, mixed> $included_components
 * @property string|null $safety_info
 * @property string|null $warranty
 * @property array<string, mixed> $ph_specs
 * @property array<string, mixed> $synonyms
 * @property string $search_text
 * @property string|null $embedding_ref
 * @property string $status
 * @property int|null $submitted_by_tenant_id
 * @property int $row_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category|null $category
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, ProductAlias> $aliases
 * @property-read Collection<int, ProductImage> $images
 */
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

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->orderBy('id');
    }

    /** @return HasMany<ProductAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class, 'product_id');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }
}
