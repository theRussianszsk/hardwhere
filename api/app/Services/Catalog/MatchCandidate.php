<?php

namespace App\Services\Catalog;

use App\Enums\MatchClassification;
use App\Models\CatalogProduct;

final readonly class MatchCandidate
{
    /**
     * @param  list<string>  $matchedFields
     * @param  list<string>  $warnings
     */
    public function __construct(
        public CatalogProduct $product,
        public float $score,
        public MatchClassification $classification,
        public array $matchedFields,
        public array $warnings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'brand' => $this->product->brand,
                'model_number' => $this->product->model_number,
                'barcode' => $this->product->barcode,
                'category' => $this->product->category,
                'attributes' => $this->product->attributes ?? [],
            ],
            'score' => round($this->score, 3),
            'classification' => $this->classification->value,
            'matched_fields' => $this->matchedFields,
            'warnings' => $this->warnings,
        ];
    }
}
