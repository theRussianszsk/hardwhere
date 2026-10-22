<?php

namespace App\Models;

use Database\Factories\CatalogProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $brand
 * @property string|null $model_number
 * @property string|null $barcode
 * @property string|null $category
 * @property array<int, string>|null $sku_aliases
 * @property array<string, string|int|float>|null $attributes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'brand', 'model_number', 'barcode', 'category', 'sku_aliases', 'attributes'])]
class CatalogProduct extends Model
{
    /** @use HasFactory<CatalogProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sku_aliases' => 'array',
            'attributes' => 'array',
        ];
    }
}
