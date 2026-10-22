<?php

namespace App\Ai;

interface VisionProvider
{
    /**
     * @param  array{hint?: string|null, filename?: string|null}  $context  `hint`/`filename` only have meaning to the mock provider (demo scenario selection)
     *
     * @throws AiProviderException when the provider is unreachable, misconfigured, or returns unusable output
     */
    public function analyze(string $imageBytes, string $mime, array $context = []): AnalysisResult;
}
