<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($ctx = TenantContext::current()) {
            $builder->where($model->getTable().'.tenant_id', $ctx->tenantId);
        }
    }
}
