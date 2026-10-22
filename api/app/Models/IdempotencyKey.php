<?php

namespace App\Models;


class IdempotencyKey extends DomainModel
{
    protected $table = 'idempotency_keys';

    public $timestamps = false;
}
