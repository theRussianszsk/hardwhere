<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

class PurchaseReceiptItem extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'purchase_receipt_items';

    public $timestamps = false;
}
