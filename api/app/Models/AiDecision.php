<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $ai_analysis_id
 * @property int $user_id
 * @property string $decision
 * @property int|null $variant_id
 * @property int|null $inventory_item_id
 * @property array<string, mixed> $tenant_input
 * @property array<string, mixed> $ai_suggestion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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
