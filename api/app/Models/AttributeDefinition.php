<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $category_id
 * @property string $key
 * @property string $label
 * @property string $data_type
 * @property string|null $unit
 * @property bool $is_variant_axis
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 */
class AttributeDefinition extends DomainModel
{
    protected $table = 'attribute_definitions';

    protected function casts(): array
    {
        return [
            'is_variant_axis' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
