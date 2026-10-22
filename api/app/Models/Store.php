<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property string $address_line
 * @property string|null $barangay
 * @property string $city
 * @property string $province
 * @property string|null $postal_code
 * @property string $country
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $phone
 * @property string|null $email
 * @property array<string, mixed> $hours
 * @property bool $supports_pickup
 * @property bool $supports_delivery
 * @property bool $is_active
 * @property int $row_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant|null $tenant
 * @property-read Collection<int, InventoryLocation> $locations
 */
class Store extends DomainModel
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'stores';

    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'supports_pickup' => 'boolean',
            'supports_delivery' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return HasMany<InventoryLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(InventoryLocation::class, 'store_id');
    }
}
