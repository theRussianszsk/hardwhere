<?php

namespace App\Http\Resources;

class PosSessionResource extends FlatResource
{
    protected const FIELDS = ['id', 'register_id', 'opened_at', 'closed_at', 'opening_float_cents', 'closing_cash_cents'];
}
