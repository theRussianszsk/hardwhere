<?php

namespace App\Http\Requests\Api;


class StaffRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'name' => ['required_without:user_exists', 'nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'min:10'],
            'role' => ['required', 'in:store_manager,inventory_manager,cashier,marketplace_manager,staff'],
            'store_id' => ['nullable', 'integer'],
        ];
    }
}
