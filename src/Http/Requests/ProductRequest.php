<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
            'code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_mandatory' => ['sometimes', 'boolean'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
