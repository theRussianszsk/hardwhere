<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $variant_id
 * @property string $key
 * @property string|null $value_text
 * @property float|null $value_number
 * @property string|null $unit
 * @property-read ProductVariant|null $variant
 */
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

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
