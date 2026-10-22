<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends DomainModel
{
    use SoftDeletes;

    protected $table = 'tenants';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class, 'tenant_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class, 'tenant_id');
    }
}
