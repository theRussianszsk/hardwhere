<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** Base for flat resources: a whitelist of columns, nothing else leaves the server. */
abstract class FlatResource extends JsonResource
{
    /** @var list<string> */
    protected const FIELDS = [];

    public function toArray($request): array
    {
        return $this->resource->only(static::FIELDS);
    }
}
