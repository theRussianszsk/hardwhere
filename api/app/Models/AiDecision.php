<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

class AiDecision extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'ai_decisions';

    protected function casts(): array
    {
        return [
            'tenant_input' => 'array',
            'ai_suggestion' => 'array',
        ];
    }
}
