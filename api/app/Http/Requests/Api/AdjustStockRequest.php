<?php

namespace App\Http\Requests\Api;

class AdjustStockRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Only reasons that are complete on their own. Opening balances come from stocking an item, received stock from
        // purchase-receipts (receipt row + cost update), transfers from a paired transfer: none may be posted bare here.
        return [
            'location_id' => ['required', 'integer'],
            'movement_type' => ['required', 'in:manual_adjustment,return,damage,loss,correction'],
            'quantity_delta' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['nullable', 'string', 'max:300', 'required_if:movement_type,manual_adjustment,damage,loss,correction'],
        ];
    }
}
