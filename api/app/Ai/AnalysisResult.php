<?php

namespace App\Ai;

final readonly class AnalysisResult
{
    /** @param  array<string, mixed>|null  $raw  provider payload kept for audit (never contains the API key or image bytes) */
    public function __construct(
        public ProductExtraction $extraction,
        public string $provider,
        public ?string $model,
        public bool $isMock,
        public ?array $raw = null,
    ) {}
}
