<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\Household;

/**
 * @mixin Household
 */
class HouseholdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // All members are equal and may invite, so the join code is visible
            // to members (this resource is only ever returned to members).
            'join_code' => $this->join_code,
            // Phase-2 theme keys (null = client derives a default from the id).
            'color' => $this->color,
            'icon' => $this->icon,
        ];
    }
}
