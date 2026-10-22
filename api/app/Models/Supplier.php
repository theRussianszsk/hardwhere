<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends DomainModel
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'suppliers';
}
