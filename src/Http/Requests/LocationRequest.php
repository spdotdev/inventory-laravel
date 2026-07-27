<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spdotdev\Inventory\Enums\HouseholdColor;
use Spdotdev\Inventory\Enums\HouseholdIcon;
use Spdotdev\Inventory\Enums\StorageType;

class LocationRequest extends FormRequest
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
            'type' => [$required, Rule::enum(StorageType::class)],
            // Location theming (mirrors ShelfRequest/UpdateHouseholdRequest
            // exactly, same palette-key enums): `sometimes|nullable` — an
            // explicit null clears the theme back to the client-derived
            // default, applied via $location->update() in
            // LocationController::update. Absent entirely, the key is
            // untouched. Locations have no is_system concept, so — unlike
            // shelves — there is no system guard to pair with this.
            'color' => ['sometimes', 'nullable', Rule::enum(HouseholdColor::class)],
            'icon' => ['sometimes', 'nullable', Rule::enum(HouseholdIcon::class)],
        ];
    }
}
