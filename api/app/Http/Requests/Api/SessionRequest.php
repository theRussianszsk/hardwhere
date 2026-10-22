<?php

namespace App\Http\Requests\Api;


class SessionRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'register_id' => ['required', 'integer'],
            'opening_float_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
