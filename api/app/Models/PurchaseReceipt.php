<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class, 'purchase_receipt_id');
    }
}
