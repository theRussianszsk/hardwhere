<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
