<?php

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        // Defense in depth only: services always scope explicitly with forTenant().
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $model->tenant_id ??= TenantContext::current()?->tenantId
                ?? throw new LogicException(class_basename($model).' requires an explicit tenant_id.');
        });
    }

    /** @param  Builder<static>  $query */
    public function scopeForTenant(Builder $query, TenantContext|int $tenant): Builder
    {
        $id = $tenant instanceof TenantContext ? $tenant->tenantId : $tenant;

        return $query->withoutGlobalScope(TenantScope::class)->where($this->getTable().'.tenant_id', $id);
    }
}
