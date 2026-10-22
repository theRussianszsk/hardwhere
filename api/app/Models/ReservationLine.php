<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

class ReservationLine extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'reservation_lines';

    public $timestamps = false;
}
