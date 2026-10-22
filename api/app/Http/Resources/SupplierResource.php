<?php

namespace App\Http\Resources;

class SupplierResource extends FlatResource
{
    protected const FIELDS = ['id', 'name', 'contact_name', 'phone', 'email', 'lead_time_days', 'notes'];
}
