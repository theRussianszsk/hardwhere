<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AiAnalysisResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            // Which provider/model ran stays server-side (ai_analyses row); clients only learn whether the result is a demo mock.
            'id' => $this->id, 'purpose' => $this->purpose,
            'is_mock' => $this->is_mock, 'provider_label' => $this->is_mock ? 'Demo AI (deterministic mock, not live inference)' : 'AI recognition',
            'extracted' => $this->extracted, 'classification' => $this->classification, 'overall_confidence' => $this->overall_confidence,
            'requires_confirmation' => $this->requires_confirmation, 'warnings' => $this->warnings,
            'candidates' => $this->whenLoaded('candidates', fn () => $this->candidates->map(fn ($c) => [
                'rank' => $c->rank, 'classification' => $c->classification, 'score' => $c->score, 'matched_fields' => $c->matched_fields,
                'mismatched_fields' => $c->mismatched_fields, 'warnings' => $c->warnings,
                'product' => (new ProductResource($c->product))->resolve(), 'variant' => $c->variant ? (new VariantResource($c->variant))->resolve() : null,
            ])->values()),
            'decided' => $this->whenLoaded('decision', fn () => $this->decision !== null), 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
