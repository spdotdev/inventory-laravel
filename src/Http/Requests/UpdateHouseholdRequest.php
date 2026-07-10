<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spdotdev\Inventory\Enums\HouseholdColor;
use Spdotdev\Inventory\Enums\HouseholdIcon;

/**
 * Partial household update (PATCH semantics): only keys present in the request
 * are applied. `color`/`icon` accept an explicit null to clear the theme back
 * to the client-derived default. Shared by the API and the web UI.
 */
class UpdateHouseholdRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'color' => ['sometimes', 'nullable', Rule::enum(HouseholdColor::class)],
            'icon' => ['sometimes', 'nullable', Rule::enum(HouseholdIcon::class)],
        ];
    }
}
