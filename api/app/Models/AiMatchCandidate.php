<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ai_analysis_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string $classification
 * @property float $score
 * @property array<string, mixed> $matched_fields
 * @property array<string, mixed> $mismatched_fields
 * @property array<string, mixed> $warnings
 * @property int $rank
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 */
class AiMatchCandidate extends DomainModel
{
    protected $table = 'ai_match_candidates';

    protected function casts(): array
    {
        return [
            'matched_fields' => 'array',
            'mismatched_fields' => 'array',
            'warnings' => 'array',
            'score' => 'float',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
