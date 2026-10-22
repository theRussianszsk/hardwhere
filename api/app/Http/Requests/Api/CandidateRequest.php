<?php

namespace App\Http\Requests\Api;

use App\Models\ProductBarcode;

class CandidateRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:160'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model_number' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'attributes' => ['nullable', 'array', 'max:30'],
            'variants' => ['nullable', 'array', 'max:10'],
            'variants.*.name' => ['required', 'string', 'max:160'],
            'variants.*.model_number' => ['nullable', 'string', 'max:80'],
            'variants.*.attributes' => ['nullable', 'array', 'max:30'],
            'variants.*.pack_quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'variants.*.uom' => ['nullable', 'string', 'max:16'],
            'variants.*.barcodes' => ['nullable', 'array', 'max:5'],
            'variants.*.barcodes.*' => ['string', 'in:'.implode(',', ProductBarcode::TYPES)],
        ];
    }
}
