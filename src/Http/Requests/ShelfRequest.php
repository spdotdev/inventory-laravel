<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShelfRequest extends FormRequest
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
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
