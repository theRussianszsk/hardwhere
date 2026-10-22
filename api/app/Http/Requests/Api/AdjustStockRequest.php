<?php

namespace App\Http\Requests\Api;


class AdjustStockRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer'],
            'movement_type' => ['required', 'in:opening_balance,purchase_receipt,manual_adjustment,return,transfer_in,transfer_out,damage,loss,correction'],
            'quantity_delta' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['nullable', 'string', 'max:300', 'required_if:movement_type,manual_adjustment,damage,loss,correction'],
        ];
    }
}
