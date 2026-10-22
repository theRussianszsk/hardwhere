<?php

namespace App\Http\Requests\Api;


class TransitionRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'in:confirmed,preparing,ready_for_pickup,fulfilled,cancelled'],
            'reason' => ['nullable', 'string', 'max:200', 'required_if:to,cancelled'],
        ];
    }
}
