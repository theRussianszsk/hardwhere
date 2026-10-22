<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** @param  array<mixed>  $metadata */
    public function record(string $action, TenantContext|int|null $tenant, ?Model $subject = null, array $metadata = [], ?int $actorId = null): void
    {
        AuditEvent::create([
            'tenant_id' => $tenant instanceof TenantContext ? $tenant->tenantId : $tenant,
            'actor_user_id' => $actorId ?? ($tenant instanceof TenantContext ? $tenant->userId : null),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip' => request()->ip(),
        ]);
    }
}
