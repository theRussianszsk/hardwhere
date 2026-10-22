<?php

namespace App\Http\Resources;

class LocationResource extends FlatResource
{
    protected const FIELDS = ['id', 'store_id', 'name', 'code', 'is_sellable'];
}
