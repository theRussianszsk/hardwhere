<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function candidates(): HasMany
    {
        return $this->hasMany(AiMatchCandidate::class, 'ai_analysis_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(AiDecision::class, 'ai_analysis_id');
    }
}
