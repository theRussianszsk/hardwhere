<?php

namespace App\Http\Requests\Api;


class ConfirmIntakeRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:confirmed_existing,selected_variant,created_candidate,rejected'],
            'variant_id' => ['required_if:decision,confirmed_existing,selected_variant', 'prohibited_if:decision,created_candidate,rejected', 'integer'],
            'new_product' => ['required_if:decision,created_candidate', 'prohibited_unless:decision,created_candidate', 'array'],
            'new_product.category_id' => ['required_with:new_product', 'integer', 'exists:categories,id'],
            'new_product.name' => ['required_with:new_product', 'string', 'max:160'],
            'new_product.brand' => ['nullable', 'string', 'max:80'],
            'new_product.model_number' => ['nullable', 'string', 'max:80'],
            'new_product.description' => ['nullable', 'string', 'max:2000'],
            'new_product.attributes' => ['nullable', 'array', 'max:30'],
            'new_product.variants' => ['nullable', 'array', 'max:10'],
            'new_product.variants.*.name' => ['required', 'string', 'max:160'],
            'new_product.variants.*.model_number' => ['nullable', 'string', 'max:80'],
            'new_product.variants.*.attributes' => ['nullable', 'array', 'max:30'],
            'new_product.variants.*.pack_quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'new_product.variants.*.uom' => ['nullable', 'string', 'max:16'],
            'new_product.variants.*.barcodes' => ['nullable', 'array', 'max:5'],
            'inventory' => ['required_unless:decision,rejected', 'array'],
            'inventory.tenant_sku' => ['required_with:inventory', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\\-\\/]+$/'],
            'inventory.cost_cents' => ['required_with:inventory', 'integer', 'min:0', 'max:100000000'],
            'inventory.price_cents' => ['required_with:inventory', 'integer', 'min:1', 'max:100000000'],
            'inventory.quantity' => ['required_with:inventory', 'integer', 'min:0', 'max:1000000'],
            'inventory.reorder_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'inventory.supplier_id' => ['nullable', 'integer'],
            'inventory.store_id' => ['required_with:inventory', 'integer'],
            'inventory.location_id' => ['nullable', 'integer'],
        ];
    }
}
