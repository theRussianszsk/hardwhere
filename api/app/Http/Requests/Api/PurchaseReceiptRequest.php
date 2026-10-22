<?php

namespace App\Http\Requests\Api;


class PurchaseReceiptRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:300'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.inventory_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
