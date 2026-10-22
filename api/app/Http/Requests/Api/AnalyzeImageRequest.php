<?php

namespace App\Http\Requests\Api;

class AnalyzeImageRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:'.config('hardwhere.ai.max_image_kb')],
            'hint' => ['nullable', 'string', 'max:60'],
        ];
    }
}
