<?php

namespace App\Http\Middleware;

use App\Enums\TenantRole;
use App\Models\PersonalAccessToken;
use App\Models\TenantMembership;
use App\Support\ApiException;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves the tenant from the access-token row (never from a header or body) and re-checks the membership
 * on every request, so removing a staff member or suspending a tenant takes effect immediately.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->can('vendor') || ! $token->tenant_id) {
            throw ApiException::forbidden('A vendor session is required.');
        }

        $membership = TenantMembership::query()->with('tenant')
            ->where('tenant_id', $token->tenant_id)->where('user_id', $token->tokenable_id)->first();

        if (! $membership || $membership->tenant?->status !== 'active') {
            $token->delete();
            throw new ApiException('membership_revoked', 'Your access to this business was revoked.', 403);
        }

        TenantContext::bind(new TenantContext(
            tenantId: $membership->tenant_id,
            userId: $membership->user_id,
            role: TenantRole::from($membership->role),
            storeId: $membership->store_id,
            vatRateBps: $membership->tenant->vat_rate_bps,
            reservationTtlMinutes: $membership->tenant->reservation_ttl_minutes,
        ));

        try {
            return $next($request);
        } finally {
            TenantContext::bind(null);
        }
    }
}
