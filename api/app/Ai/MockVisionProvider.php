<?php

namespace App\Ai;

/**
 * DEMO PROVIDER. Returns canned, deterministic extractions; it never looks at the pixels. Every result is flagged is_mock=true
 * and surfaced in the UI as "Demo AI". Scenario = explicit `hint`, else a stable pick from the image hash.
 */
class MockVisionProvider implements VisionProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function scenarios(): array
    {
        $c = fn (float $n, float $b, float $m, float $cat = 0.9, float $a = 0.85, float $bc = 0.0) => ['name' => $n, 'brand' => $b, 'model' => $m, 'category' => $cat, 'attributes' => $a, 'barcode' => $bc];

        return [
            'makita-hp1630' => ['name' => 'Hammer Drill 16mm', 'brand' => 'Makita', 'model' => 'HP1630', 'category' => 'Power Tools', 'attributes' => ['voltage_v' => 220, 'wattage_w' => 710, 'chuck_mm' => 13], 'confidence' => $c(0.93, 0.95, 0.9), 'overall_confidence' => 0.92],
            'makita-hp1630k' => ['name' => 'Hammer Drill 16mm with case', 'brand' => 'Makita', 'model' => 'HP1630K', 'category' => 'Power Tools', 'attributes' => ['voltage_v' => 220, 'wattage_w' => 710], 'confidence' => $c(0.9, 0.95, 0.88), 'overall_confidence' => 0.9],
            'bosch-gsb13' => ['name' => 'Impact Drill', 'brand' => 'Bosch', 'model' => 'GSB 13 RE', 'category' => 'Power Tools', 'attributes' => ['voltage_v' => 220, 'wattage_w' => 600], 'confidence' => $c(0.9, 0.93, 0.85), 'overall_confidence' => 0.9],
            'barcode-visible' => ['name' => 'Claw Hammer 16oz', 'brand' => 'Stanley', 'model' => '51-188', 'category' => 'Hand Tools', 'attributes' => ['weight_oz' => 16], 'barcode_text' => '4800000100017', 'confidence' => $c(0.9, 0.9, 0.8, 0.9, 0.8, 0.95), 'overall_confidence' => 0.93],
            'unbarcoded-tek-screw' => ['name' => 'Tek screw self-drilling', 'brand' => null, 'model' => null, 'category' => 'Fasteners', 'attributes' => ['size' => '#10 x 1"', 'material' => 'zinc'], 'pack_quantity' => 100, 'confidence' => $c(0.82, 0.0, 0.0, 0.88, 0.8), 'overall_confidence' => 0.7],
            'ambiguous-cordless-drill' => ['name' => 'Cordless drill', 'brand' => 'Black+Decker', 'model' => 'BCD001', 'category' => 'Power Tools', 'attributes' => ['voltage_v' => 20], 'confidence' => $c(0.8, 0.45, 0.5, 0.9, 0.6), 'overall_confidence' => 0.62],
            'cordless-drill-12v' => ['name' => 'Cordless drill 12V', 'brand' => 'Black+Decker', 'model' => 'BCD001', 'category' => 'Power Tools', 'attributes' => ['voltage_v' => 12], 'confidence' => $c(0.85, 0.85, 0.8), 'overall_confidence' => 0.84],
            'conflicting-barcode' => ['name' => 'Claw Hammer', 'brand' => 'Tolsen', 'model' => '25101', 'category' => 'Hand Tools', 'attributes' => [], 'barcode_text' => '4800000100017', 'confidence' => $c(0.7, 0.75, 0.7, 0.9, 0.5, 0.9), 'overall_confidence' => 0.75],
            'unknown-product' => ['name' => 'Expanding foam sealant 750ml', 'brand' => 'Kingfoam', 'model' => 'KF-750', 'category' => 'Building Supplies', 'attributes' => ['capacity_ml' => 750], 'confidence' => $c(0.8, 0.7, 0.7, 0.85, 0.7), 'overall_confidence' => 0.74],
            'blurry' => ['name' => null, 'brand' => null, 'model' => null, 'category' => null, 'attributes' => [], 'confidence' => $c(0.1, 0.0, 0.0, 0.1, 0.0), 'overall_confidence' => 0.12, 'image_sufficient' => false, 'notes' => ['Image is too blurry to read any label.']],
        ];
    }

    public function analyze(string $imageBytes, string $mime, array $context = []): AnalysisResult
    {
        $scenarios = self::scenarios();
        $key = $context['hint'] ?? null;
        if (! $key || ! isset($scenarios[$key])) {
            $keys = array_keys($scenarios);
            $key = $keys[hexdec(substr(hash('sha256', $imageBytes), 0, 8)) % count($keys)];
        }

        $s = $scenarios[$key] + ['pack_quantity' => null, 'barcode_text' => null, 'image_sufficient' => true, 'notes' => []];
        $s['notes'][] = 'Demo AI (deterministic mock): not a live model result.';

        return new AnalysisResult(ProductExtraction::fromModelOutput($s), 'mock', 'mock-fixtures-v1', true, ['scenario' => $key]);
    }
}
