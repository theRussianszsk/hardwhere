<?php

namespace App\Ai;

/** What a vision model says it sees. Every field is a suggestion with its own confidence; nothing here is trusted as fact. */
final readonly class ProductExtraction
{
    /**
     * @param  array<string, scalar>  $attributes  e.g. voltage_v => 220, pack_quantity => 100
     * @param  array<string, float>  $confidence  per field, 0..1 (keys: name, brand, model, category, attributes, barcode)
     * @param  list<string>  $notes
     * @param  array{min_php: int, max_php: int}|null  $estimatedPricePhp  a model guess in whole pesos, never a quote
     */
    public function __construct(
        public ?string $name,
        public ?string $brand,
        public ?string $model,
        public ?string $partNumber,
        public ?string $category,
        public array $attributes,
        public ?int $packQuantity,
        public ?string $barcodeText,
        public array $confidence,
        public float $overallConfidence,
        public bool $imageSufficient = true,
        public array $notes = [],
        public ?string $productType = null,
        public ?string $subcategory = null,
        public ?string $material = null,
        public ?string $description = null,
        public ?array $estimatedPricePhp = null,
        public bool $isHardware = true,
    ) {}

    public function conf(string $field): float
    {
        return $this->confidence[$field] ?? 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'brand' => $this->brand, 'model' => $this->model, 'part_number' => $this->partNumber,
            'category' => $this->category, 'attributes' => $this->attributes, 'pack_quantity' => $this->packQuantity,
            'barcode_text' => $this->barcodeText, 'confidence' => $this->confidence, 'overall_confidence' => $this->overallConfidence,
            'image_sufficient' => $this->imageSufficient, 'notes' => $this->notes,
            'product_type' => $this->productType, 'subcategory' => $this->subcategory, 'material' => $this->material,
            'description' => $this->description, 'estimated_price_php' => $this->estimatedPricePhp, 'is_hardware' => $this->isHardware,
        ];
    }

    /**
     * Models answer "18V", "710 W" or "16 mm" where the catalogue stores numbers. Keys that carry a unit suffix
     * (voltage_v, wattage_w, length_mm, capacity_ml ...) become numbers so structured comparison and numeric search work.
     */
    private static function normalizeAttribute(string $key, mixed $value): mixed
    {
        if (is_string($value) && preg_match('/_(v|w|a|ah|mah|hz|rpm|mm|cm|m|ml|l|g|kg|oz|lb)$/', $key)
            && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[a-zA-Zµ²\/%]*\s*$/u', str_replace(',', '.', $value), $m)) {
            return str_contains($m[1], '.') ? (float) $m[1] : (int) $m[1];
        }

        return $value;
    }

    /** Validates untrusted model output (decoded JSON) into a safe DTO. */
    public static function fromModelOutput(mixed $raw): self
    {
        is_array($raw) || throw new AiProviderException('The model did not return a JSON object.');

        $str = fn ($v, int $max = 160) => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $clamp = fn ($v) => is_numeric($v) ? max(0.0, min(1.0, (float) $v)) : 0.0;

        $attributes = [];
        foreach (is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [] as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k) && (is_int($v) || is_float($v) || is_string($v) || is_bool($v))) {
                $attributes[$k] = self::normalizeAttribute($k, is_string($v) ? mb_substr($v, 0, 80) : $v);
            }
        }

        $confidence = [];
        foreach (is_array($raw['confidence'] ?? null) ? $raw['confidence'] : [] as $k => $v) {
            is_string($k) && $confidence[$k] = $clamp($v);
        }

        $price = is_array($raw['estimated_price_php'] ?? null) ? $raw['estimated_price_php'] : [];
        $min = is_numeric($price['min'] ?? null) ? (int) round((float) $price['min']) : null;
        $max = is_numeric($price['max'] ?? null) ? (int) round((float) $price['max']) : null;
        $estimate = $min !== null && $max !== null && $min >= 0 && $max >= $min && $max <= 10_000_000 ? ['min_php' => $min, 'max_php' => $max] : null;

        $notes = array_values(array_filter(array_map(fn ($n) => $str($n, 200), is_array($raw['notes'] ?? null) ? array_slice($raw['notes'], 0, 5) : [])));
        // Absent flag = hardware (older prompts, mock fixtures); an explicit false or the prompt's note marks it as not hardware.
        $isHardware = filter_var($raw['is_hardware'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $isHardware = $isHardware && ! array_filter($notes, fn ($n) => stripos($n, 'not a hardware') !== false);

        $barcode = $str($raw['barcode_text'] ?? null, 40);
        $barcode = $barcode ? preg_replace('/\D+/', '', $barcode) : null;

        return new self(
            name: $str($raw['name'] ?? null), brand: $str($raw['brand'] ?? null), model: $str($raw['model'] ?? null, 80),
            partNumber: $str($raw['part_number'] ?? null, 80), category: $str($raw['category'] ?? null, 80),
            attributes: $attributes,
            packQuantity: isset($raw['pack_quantity']) && is_numeric($raw['pack_quantity']) && $raw['pack_quantity'] >= 1 ? (int) $raw['pack_quantity'] : null,
            barcodeText: $barcode ?: null, confidence: $confidence,
            overallConfidence: $clamp($raw['overall_confidence'] ?? 0),
            imageSufficient: ! array_key_exists('image_sufficient', $raw) || (bool) $raw['image_sufficient'],
            notes: $notes,
            productType: $str($raw['product_type'] ?? null, 80), subcategory: $str($raw['subcategory'] ?? null, 80),
            material: $str($raw['material'] ?? null, 80), description: $str($raw['description'] ?? null, 300), estimatedPricePhp: $estimate, isHardware: $isHardware,
        );
    }
}
