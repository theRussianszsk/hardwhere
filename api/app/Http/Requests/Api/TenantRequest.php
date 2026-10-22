<?php

namespace App\Http\Requests\Api;


class TenantRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:tenants,slug'],
            'owner_email' => ['required', 'email'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_password' => ['required', 'string', 'min:10'],
        ];
    }
}
