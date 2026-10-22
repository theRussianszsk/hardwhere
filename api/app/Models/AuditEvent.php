<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;

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
