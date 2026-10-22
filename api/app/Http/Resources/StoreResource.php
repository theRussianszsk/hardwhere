<?php

namespace App\Http\Resources;

class StoreResource extends FlatResource
{
    protected const FIELDS = ['id', 'name', 'code', 'address_line', 'barangay', 'city', 'province', 'postal_code', 'lat', 'lng', 'phone', 'email', 'hours', 'supports_pickup', 'supports_delivery', 'is_active', 'row_version', 'updated_at'];
}
