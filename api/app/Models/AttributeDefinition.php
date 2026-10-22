<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeDefinition extends DomainModel
{
    protected $table = 'attribute_definitions';

    protected function casts(): array
    {
        return [
            'is_variant_axis' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
