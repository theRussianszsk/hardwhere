<?php

namespace App\Http\Resources;

class PosRegisterResource extends FlatResource
{
    protected const FIELDS = ['id', 'store_id', 'name', 'is_active'];
}
