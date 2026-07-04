<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    /**
     * Upper bound for a stored quantity and a single stock delta. Well under the
     * `unsignedInteger` column ceiling (~4.29B) so a large/typo'd value is a clean
     * 422 rather than a MySQL "out of range" 500 (W14). Shared with the add/remove
     * amount validation in ProductController.
     */
    public const MAX_QUANTITY = 1_000_000;

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
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_QUANTITY],
        ];
    }
}
