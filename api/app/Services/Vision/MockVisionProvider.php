<?php

namespace App\Services\Vision;

/**
 * Deterministic demo provider. Output is NOT real inference and is flagged is_mock.
 * Pick a fixture by including a keyword in the uploaded filename.
 */
class MockVisionProvider implements ProductVisionProvider
{
    public function analyze(string $imageBytes, string $mimeType, ?string $filename = null): ProductAnalysis
    {
        $name = strtolower($filename ?? '');

        $fixture = match (true) {
            str_contains($name, 'drill-220') => [
                'name' => 'Cordless Drill Driver', 'brand' => 'Makita', 'model_number' => 'DF333D',
                'category' => 'Power tools',
                'attributes' => ['voltage' => '220V', 'color' => 'teal'],
                'confidences' => ['name' => 0.9, 'brand' => 0.92, 'model_number' => 0.88],
            ],
            str_contains($name, 'drill') => [
                'name' => 'Cordless Drill Driver', 'brand' => 'Makita', 'model_number' => 'DF333D',
                'category' => 'Power tools',
                'attributes' => ['voltage' => '12V', 'color' => 'teal'],
                'confidences' => ['name' => 0.9, 'brand' => 0.92, 'model_number' => 0.88],
            ],
            str_contains($name, 'nails') => [
                'name' => 'Common Wire Nails 2 inch', 'category' => 'Fasteners', 'packaging' => 'box',
                'attributes' => ['length' => '2in', 'pack_quantity' => 500],
                'confidences' => ['name' => 0.7, 'brand' => 0.1, 'model_number' => 0.1],
            ],
            str_contains($name, 'barcode') => [
                'name' => 'Claw Hammer 16oz', 'brand' => 'Stanley', 'category' => 'Hand tools',
                'barcode' => '3253561510130',
                'attributes' => ['weight' => '16oz'],
                'confidences' => ['name' => 0.8, 'brand' => 0.85, 'model_number' => 0.2],
            ],
            default => [
                'attributes' => [],
                'confidences' => ['name' => 0.1, 'brand' => 0.1, 'model_number' => 0.05],
                'image_sufficient' => false,
            ],
        };

        return ProductAnalysis::fromProviderArray($fixture, 'mock', true);
    }
}
