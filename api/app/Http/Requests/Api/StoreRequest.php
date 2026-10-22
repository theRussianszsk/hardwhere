<?php

namespace App\Http\Requests\Api;


class StoreRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:24', 'alpha_dash'],
            'address_line' => ['required', 'string', 'max:200'],
            'barangay' => ['nullable', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'province' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'hours' => ['nullable', 'array'],
            'supports_pickup' => ['boolean'],
            'supports_delivery' => ['boolean'],
        ];
    }
}
