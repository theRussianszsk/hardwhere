<?php

namespace App\Http\Requests\Api;

class StaffRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:120'], // required only when the account does not exist yet (checked in the controller)
            'password' => ['nullable', 'string', 'min:10'],
            'role' => ['required', 'in:store_manager,inventory_manager,cashier,marketplace_manager,staff'],
            'store_id' => ['nullable', 'integer'],
        ];
    }
}
