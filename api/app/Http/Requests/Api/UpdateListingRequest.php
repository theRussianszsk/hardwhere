<?php

namespace App\Http\Requests\Api;

class UpdateListingRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_row_version' => ['required', 'integer'],
            'title_override' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['sometimes', 'integer', 'min:1', 'max:100000000'],
            'sale_price_cents' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'pickup_available' => ['sometimes', 'boolean'],
            'delivery_available' => ['sometimes', 'boolean'],
            'min_order_qty' => ['sometimes', 'integer', 'min:1'],
            'max_order_qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'gte:min_order_qty'],
            'lead_time_hours' => ['sometimes', 'integer', 'min:0', 'max:2000'],
            'stock_buffer' => ['sometimes', 'integer', 'min:0'],
            'show_exact_stock' => ['sometimes', 'boolean'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }
}
