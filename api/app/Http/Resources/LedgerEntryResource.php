<?php

namespace App\Http\Resources;

class LedgerEntryResource extends FlatResource
{
    protected const FIELDS = ['id', 'inventory_item_id', 'location_id', 'movement_type', 'quantity_delta', 'before_quantity', 'after_quantity', 'reserved_delta', 'before_reserved', 'after_reserved', 'reference_type', 'reference_id', 'actor_type', 'actor_id', 'note', 'occurred_at'];
}
