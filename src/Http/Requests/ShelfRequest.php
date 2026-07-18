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
            // Column is unsignedInteger (~4.29B ceiling); cap well under that so
            // a typo'd value is a clean 422 rather than an overflowing INSERT.
            'position' => ['sometimes', 'integer', 'min:0', 'max:'.ProductRequest::MAX_QUANTITY],
            // Reparenting. No UI gesture exposes this yet, but the location
            // delete's move_contents strategy IS a reparent, and a future
            // drag-between-locations should be a client change, not a migration.
            // Household scoping is enforced in the controller — a Rule::exists
            // here cannot see the household.
            'location_id' => ['sometimes', 'integer'],
        ];
    }
}
