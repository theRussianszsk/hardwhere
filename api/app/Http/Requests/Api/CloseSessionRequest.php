<?php

namespace App\Http\Requests\Api;

class CloseSessionRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['closing_cash_cents' => ['required', 'integer', 'min:0']];
    }
}
