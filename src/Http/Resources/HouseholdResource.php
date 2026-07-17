<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

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
        $user = $request->user();
        $role = $user instanceof User ? $this->resource->roleOf($user) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            // All members may see the join code and invite others — that's
            // unrelated to the restructure/manage-members role gate.
            'join_code' => $this->join_code,
            // Phase-2 theme keys (null = client derives a default from the id).
            'color' => $this->color,
            'icon' => $this->icon,
            // The CALLER's own role + derived capabilities, so neither client
            // re-implements the role→capability mapping (roles design spec).
            'role' => $role,
            'can_restructure' => $user instanceof User && Gate::forUser($user)->allows('restructure', $this->resource),
            'can_manage_members' => $user instanceof User && Gate::forUser($user)->allows('manageMembers', $this->resource),
        ];
    }
}
