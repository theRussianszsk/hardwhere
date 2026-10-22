<?php

namespace App\Services\Vision;

interface ProductVisionProvider
{
    /**
     * Extract product details from an image.
     *
     * @param  string  $imageBytes  raw image contents
     * @param  string  $mimeType  validated image mime type
     * @param  string|null  $filename  original upload name (only the mock provider reads it)
     *
     * @throws VisionProviderException when the provider is unreachable or returns unusable output
     */
    public function analyze(string $imageBytes, string $mimeType, ?string $filename = null): ProductAnalysis;
}
