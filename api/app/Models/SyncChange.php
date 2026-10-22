<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $audience
 * @property string $entity
 * @property int $entity_id
 * @property string $created_at
 * @property string $txid
 */
class SyncChange extends DomainModel
{
    protected $table = 'sync_changes';

    public $timestamps = false;
}
