<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $supplier_id
 * @property int $location_id
 * @property string|null $reference
 * @property int $received_by
 * @property Carbon $received_at
 * @property int $total_cost_cents
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PurchaseReceiptItem> $items
 */
class PurchaseReceipt extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'purchase_receipts';

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    /** @return HasMany<PurchaseReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class, 'purchase_receipt_id');
    }
}
