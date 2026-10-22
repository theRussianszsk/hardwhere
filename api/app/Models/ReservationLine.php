<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $reservation_id
 * @property int $order_item_id
 * @property int $inventory_item_id
 * @property int $location_id
 * @property int $quantity
 */
class ReservationLine extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'reservation_lines';

    public $timestamps = false;
}
