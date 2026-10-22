<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $variant_id
 * @property string $code
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductVariant|null $variant
 */
class ProductBarcode extends DomainModel
{
    /** Mirrors the product_barcodes_type_chk CHECK constraint. */
    public const TYPES = ['UPC', 'EAN8', 'EAN13', 'GTIN14', 'OTHER'];

    protected $table = 'product_barcodes';

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
