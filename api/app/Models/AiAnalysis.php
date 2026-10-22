<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int $user_id
 * @property string $purpose
 * @property string $provider
 * @property string|null $model
 * @property bool $is_mock
 * @property string $status
 * @property string|null $image_path
 * @property string $image_sha256
 * @property string $image_mime
 * @property array<string, mixed> $extracted
 * @property array<string, mixed> $warnings
 * @property array<string, mixed>|null $raw_response
 * @property float|null $overall_confidence
 * @property string|null $classification
 * @property bool $requires_confirmation
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AiMatchCandidate> $candidates
 * @property-read AiDecision|null $decision
 */
class AiAnalysis extends DomainModel
{
    protected $table = 'ai_analyses';

    protected function casts(): array
    {
        return [
            'extracted' => 'array',
            'warnings' => 'array',
            'raw_response' => 'array',
            'is_mock' => 'boolean',
            'requires_confirmation' => 'boolean',
            'overall_confidence' => 'float',
        ];
    }

    /** @return HasMany<AiMatchCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(AiMatchCandidate::class, 'ai_analysis_id');
    }

    /** @return HasOne<AiDecision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(AiDecision::class, 'ai_analysis_id');
    }
}
