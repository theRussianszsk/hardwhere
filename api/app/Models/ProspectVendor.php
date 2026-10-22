<?php

namespace App\Models;

/** A hardware store that is not on the platform yet. */
class ProspectVendor extends DomainModel
{
    protected $table = 'prospect_vendors';

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'brands' => 'array',
            'unsubscribed_at' => 'datetime',
        ];
    }
}
