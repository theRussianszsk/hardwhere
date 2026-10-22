<?php

namespace App\Services\Vision;

use InvalidArgumentException;

/**
 * Validated, provider-agnostic result of analysing a product photo.
 */
final readonly class ProductAnalysis
{
    /**
     * @param  array<string, string|int|float>  $attributes  e.g. voltage, pack_quantity, color
     * @param  array<string, float>  $confidences  per-field confidence in 0..1
     */
    public function __construct(
        public ?string $name,
        public ?string $brand,
        public ?string $modelNumber,
        public ?string $category,
        public ?string $barcode,
        public ?string $packaging,
        public array $attributes,
        public array $confidences,
        public bool $imageSufficient,
        public string $provider,
        public bool $isMock,
    ) {}

    /**
     * Build from untrusted provider output, rejecting malformed payloads.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromProviderArray(array $data, string $provider, bool $isMock): self
    {
        foreach (['name', 'brand', 'model_number', 'category', 'barcode', 'packaging'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw new InvalidArgumentException("Field [{$key}] must be a string.");
            }
        }

        $attributes = $data['attributes'] ?? [];
        $confidences = $data['confidences'] ?? [];

        if (! is_array($attributes) || ! is_array($confidences)) {
            throw new InvalidArgumentException('Fields [attributes] and [confidences] must be objects.');
        }

        $cleanAttributes = [];
        foreach ($attributes as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value)) && $value !== '') {
                $cleanAttributes[strtolower($key)] = $value;
            }
        }

        $cleanConfidences = [];
        foreach ($confidences as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $cleanConfidences[$key] = max(0.0, min(1.0, (float) $value));
            }
        }

        $barcode = isset($data['barcode']) ? preg_replace('/\D/', '', $data['barcode']) : null;

        return new self(
            name: self::blankToNull($data['name'] ?? null),
            brand: self::blankToNull($data['brand'] ?? null),
            modelNumber: self::blankToNull($data['model_number'] ?? null),
            category: self::blankToNull($data['category'] ?? null),
            barcode: self::blankToNull($barcode),
            packaging: self::blankToNull($data['packaging'] ?? null),
            attributes: $cleanAttributes,
            confidences: $cleanConfidences,
            imageSufficient: (bool) ($data['image_sufficient'] ?? true),
            provider: $provider,
            isMock: $isMock,
        );
    }

    /**
     * Mean confidence across identity fields that were actually extracted (absent fields don't drag it down).
     */
    public function identityConfidence(): float
    {
        $extracted = array_filter([
            'name' => $this->name,
            'brand' => $this->brand,
            'model_number' => $this->modelNumber,
        ], fn (?string $value) => $value !== null);

        $values = array_intersect_key($this->confidences, $extracted);

        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'brand' => $this->brand,
            'model_number' => $this->modelNumber,
            'category' => $this->category,
            'barcode' => $this->barcode,
            'packaging' => $this->packaging,
            'attributes' => $this->attributes,
            'confidences' => $this->confidences,
            'image_sufficient' => $this->imageSufficient,
            'provider' => $this->provider,
            'is_mock' => $this->isMock,
        ];
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
