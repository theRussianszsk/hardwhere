<?php

namespace App\Tenancy;

use App\Enums\TenantRole;
use App\Support\ApiException;

/**
 * The authenticated actor's tenant, resolved server-side from the access token row and re-verified against
 * an active membership on every request. Services take this as an explicit argument.
 */
final readonly class TenantContext
{
    public function __construct(
        public int $tenantId,
        public int $userId,
        public TenantRole $role,
        public ?int $storeId = null,
        public int $vatRateBps = 1200,
        public int $reservationTtlMinutes = 120,
    ) {}

    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    public static function bind(?self $context): void
    {
        $context ? app()->instance(self::class, $context) : app()->forgetInstance(self::class);
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->role->permissions(), true);
    }

    public function canAccessStore(int $storeId): bool
    {
        return $this->storeId === null || $this->storeId === $storeId;
    }

    public function assertStore(int $storeId): void
    {
        $this->canAccessStore($storeId) || throw ApiException::forbidden('You are not assigned to this store.');
    }
}
