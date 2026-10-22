<?php

namespace App\Http\Requests\Api;

use App\Rules\NotCardNumber;

class CheckoutRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer'],
            'pos_session_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.listing_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.discount_cents' => ['nullable', 'integer', 'min:0'],
            'items.*.expected_unit_price_cents' => ['nullable', 'integer', 'min:0'],
            'payment' => ['required', 'array'],
            'payment.method' => ['required', 'in:cash,gcash,maya,card,bank_transfer,other'],
            'payment.reference' => ['nullable', 'string', 'max:64', 'required_unless:payment.method,cash', new NotCardNumber],
            'payment.tendered_cents' => ['nullable', 'integer', 'min:0', 'required_if:payment.method,cash'],
            'customer.name' => ['nullable', 'string', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
