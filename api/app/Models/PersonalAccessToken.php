<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/** Sanctum token carrying the tenant it was issued for (null for customer and platform-admin tokens). */
class PersonalAccessToken extends SanctumToken
{
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'tenant_id'];
}
