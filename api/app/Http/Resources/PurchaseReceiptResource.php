<?php

namespace App\Http\Resources;

class PurchaseReceiptResource extends FlatResource
{
    protected const FIELDS = ['id', 'supplier_id', 'location_id', 'reference', 'received_at', 'total_cost_cents', 'notes'];
}
