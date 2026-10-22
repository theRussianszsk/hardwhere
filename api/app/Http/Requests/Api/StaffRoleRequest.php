<?php

namespace App\Http\Requests\Api;

class StaffRoleRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'in:store_manager,inventory_manager,cashier,marketplace_manager,staff'],
            'store_id' => ['nullable', 'integer'],
        ];
    }
}
