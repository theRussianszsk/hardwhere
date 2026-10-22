<?php

namespace App\Http\Controllers\Api;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Models\PersonalAccessToken;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $d = $request->validated();
        $user = User::query()->where('email', $d['email'])->first();

        // Same answer for unknown email and wrong password.
        if (! $user || ! Hash::check($d['password'], $user->password)) {
            throw new ApiException('invalid_credentials', 'Email or password is incorrect.', 401);
        }

        $membership = null;
        if ($d['app'] === 'vendor') {
            $memberships = TenantMembership::query()->with('tenant')->where('user_id', $user->id)
                ->whereHas('tenant', fn ($t) => $t->where('status', 'active'))->get();
            $membership = isset($d['tenant']) ? $memberships->first(fn ($m) => $m->tenant->slug === $d['tenant']) : ($memberships->count() === 1 ? $memberships->first() : null);

            if (! $membership) {
                throw $memberships->isEmpty() || isset($d['tenant'])
                    ? new ApiException('no_business_access', 'This account has no access to that business.', 403)
                    : ApiException::invalid('tenant_required', 'Choose which business to sign in to.', ['tenants' => $memberships->map(fn ($m) => ['slug' => $m->tenant->slug, 'name' => $m->tenant->name])->values()]);
            }
        } elseif ($d['app'] === 'admin' && ! $user->is_platform_admin) {
            throw new ApiException('no_admin_access', 'This account is not a platform administrator.', 403);
        }

        return response()->json($this->issue($user, $d['app'], $membership, $d['device_name'] ?? 'mobile'));
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $d = $request->validated();
        $user = User::create(['name' => $d['name'], 'email' => $d['email'], 'password' => $d['password'], 'phone' => $d['phone'] ?? null]);
        $this->audit->record('customer.registered', null, $user, [], $user->id);

        return response()->json($this->issue($user, 'customer', null, $d['device_name'] ?? 'mobile'), 201);
    }

    public function me(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $token instanceof PersonalAccessToken || throw new ApiException('unauthenticated', 'Sign in again.', 401);

        return response()->json(['user' => $this->userPayload($request->user())] + $this->scopePayload($token));
    }

    /** Rotates the presented (still valid) token. An expired token cannot be renewed: sign in again. */
    public function renew(Request $request): JsonResponse
    {
        $old = $request->user()->currentAccessToken();
        $old instanceof PersonalAccessToken || throw new ApiException('unauthenticated', 'Sign in again.', 401);
        $app = match (true) {
            $old->can('vendor') => 'vendor', $old->can('platform') => 'admin', default => 'customer',
        };
        $membership = $old->tenant_id ? TenantMembership::query()->with('tenant')->where('tenant_id', $old->tenant_id)->where('user_id', $old->tokenable_id)->first() : null;
        if ($app === 'vendor' && ! $membership) {
            $old->delete();
            throw new ApiException('membership_revoked', 'Your access to this business was revoked.', 403);
        }

        $payload = $this->issue($request->user(), $app, $membership, $old->name);
        $old->delete();

        return response()->json($payload);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function issue(User $user, string $app, ?TenantMembership $membership, string $device): array
    {
        [$abilities, $ttl] = match ($app) {
            'vendor' => [['vendor'], config('hardwhere.tokens.vendor_ttl_minutes')],
            'admin' => [['platform'], config('hardwhere.tokens.vendor_ttl_minutes')],
            default => [['customer'], config('hardwhere.tokens.customer_ttl_minutes')],
        };
        $new = $user->createToken(mb_substr($device, 0, 80), $abilities, now()->addMinutes($ttl));
        $new->accessToken->forceFill(['tenant_id' => $membership?->tenant_id])->save();
        $this->audit->record("auth.login.$app", $membership?->tenant_id, $user, [], $user->id);

        return ['token' => $new->plainTextToken, 'expires_at' => $new->accessToken->expires_at?->toIso8601String(), 'user' => $this->userPayload($user)] + $this->scopePayload($new->accessToken);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone];
    }

    /** @return array<string, mixed> */
    private function scopePayload(PersonalAccessToken $token): array
    {
        $membership = $token->tenant_id ? TenantMembership::query()->with('tenant')->where('tenant_id', $token->tenant_id)->where('user_id', $token->tokenable_id)->first() : null;
        $role = $membership ? TenantRole::from($membership->role) : null;

        return [
            'app' => $token->can('vendor') ? 'vendor' : ($token->can('platform') ? 'admin' : 'customer'),
            'tenant' => $membership ? ['id' => $membership->tenant->id, 'slug' => $membership->tenant->slug, 'name' => $membership->tenant->name, 'vat_rate_bps' => $membership->tenant->vat_rate_bps] : null,
            'role' => $role?->value, 'permissions' => $role?->permissions() ?? [], 'store_id' => $membership?->store_id,
        ];
    }
}
