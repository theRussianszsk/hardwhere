<?php

namespace App\Http\Requests\Api;

class LoginRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'app' => ['required', 'in:vendor,customer,admin'],
            'tenant' => ['nullable', 'string', 'max:80'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }
}
