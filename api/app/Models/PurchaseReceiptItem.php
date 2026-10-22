<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_receipt_id
 * @property int $inventory_item_id
 * @property int $quantity
 * @property int $unit_cost_cents
 */
class PurchaseReceiptItem extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'purchase_receipt_items';

    public $timestamps = false;
}
