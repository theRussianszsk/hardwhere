<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed> $metadata
 * @property string|null $ip
 * @property string $created_at
 */
class AuditEvent extends DomainModel
{
    use AppendOnly;

    protected $table = 'audit_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
