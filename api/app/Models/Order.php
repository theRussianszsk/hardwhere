<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $customer_id
 * @property string|null $order_no
 * @property string $status
 * @property string $fulfillment_type
 * @property int $subtotal_cents
 * @property int $delivery_fee_cents
 * @property int $total_cents
 * @property string $contact_name
 * @property string $contact_phone
 * @property string|null $delivery_address
 * @property string|null $notes
 * @property string $idempotency_key
 * @property Carbon $placed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int $row_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, OrderItem> $items
 * @property-read Store|null $store
 * @property-read Reservation|null $reservation
 * @property-read User|null $customer
 */
class Order extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'orders';

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'ready_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return HasOne<Reservation, $this> */
    public function reservation(): HasOne
    {
        return $this->hasOne(Reservation::class, 'order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
