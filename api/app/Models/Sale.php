<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int|null $pos_session_id
 * @property int $cashier_id
 * @property string|null $receipt_no
 * @property string $status
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property string $payment_method
 * @property string|null $payment_reference
 * @property int|null $tendered_cents
 * @property int $change_cents
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string $idempotency_key
 * @property Carbon $completed_at
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, SaleItem> $items
 * @property-read Store|null $store
 * @property-read User|null $cashier
 */
class Sale extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'sales';

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
