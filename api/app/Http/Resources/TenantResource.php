<?php

namespace App\Http\Resources;

class TenantResource extends FlatResource
{
    protected const FIELDS = ['id', 'name', 'slug', 'status', 'vat_rate_bps', 'reservation_ttl_minutes'];
}
