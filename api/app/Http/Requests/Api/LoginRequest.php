<?php

namespace App\Http\Requests\Api;

class LoginRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->has('email') && $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

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
