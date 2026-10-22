<?php

namespace App\Ai;

interface VisionProvider
{
    /**
     * @param  array{hint?: string|null, filename?: string|null, vocabulary?: array{categories?: list<string>, attributes?: list<string>}|null}  $context
     *                                                                                                                                                     `hint`/`filename` only have meaning to the mock provider (demo scenario selection); `vocabulary` is the catalog's categories and attribute keys for live models
     *
     * @throws AiProviderException when the provider is unreachable, misconfigured, or returns unusable output
     */
    public function analyze(string $imageBytes, string $mime, array $context = []): AnalysisResult;
}
