<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $scope
 * @property string $key
 * @property string $operation
 * @property string $request_hash
 * @property string|null $resource_type
 * @property int|null $resource_id
 * @property string $created_at
 */
class IdempotencyKey extends DomainModel
{
    protected $table = 'idempotency_keys';

    public $timestamps = false;
}
