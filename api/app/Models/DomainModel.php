<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base for domain models. Mass assignment is open on purpose: these models are only ever written by services
 * with explicit column arrays built from Form Request ->validated() data, never from raw request input.
 */
abstract class DomainModel extends Model
{
    protected $guarded = [];
}
