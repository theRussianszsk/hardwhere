<?php

namespace App\Http\Requests\Api;

class StoreItemRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer'],
            'tenant_sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\\-\\/]+$/'],
            'cost_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'price_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reorder_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'supplier_id' => ['nullable', 'integer'],
            'store_id' => ['required', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ];
    }
}
