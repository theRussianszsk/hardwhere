<?php

namespace App\Ai;

interface VisionProvider
{
    /**
     * @param  array{hint?: string|null}  $context  `hint` only has meaning to the mock provider (demo scenario key)
     *
     * @throws AiProviderException when the provider is unreachable, misconfigured, or returns unusable output
     */
    public function analyze(string $imageBytes, string $mime, array $context = []): AnalysisResult;
}
