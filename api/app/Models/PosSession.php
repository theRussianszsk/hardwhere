<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $register_id
 * @property int $opened_by
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property int $opening_float_cents
 * @property int|null $closing_cash_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PosRegister|null $register
 */
class PosSession extends DomainModel
{
    use BelongsToTenant;

    protected $table = 'pos_sessions';

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PosRegister, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'register_id');
    }
}
