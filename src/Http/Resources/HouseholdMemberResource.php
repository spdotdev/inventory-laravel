<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\HouseholdUserPivot;
use Spdotdev\Inventory\Models\User;

/**
 * @mixin User
 */
class HouseholdMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HouseholdUserPivot $pivot */
        $pivot = $this->pivot;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $pivot->role,
            'joined_at' => $pivot->joined_at,
        ];
    }
}
