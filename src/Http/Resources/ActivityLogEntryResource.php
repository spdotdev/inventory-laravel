<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\ActivityLogEntry;

/**
 * @mixin ActivityLogEntry
 */
class ActivityLogEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'household_id' => $this->household_id,
            'actor_id' => $this->actor_id,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject_label' => $this->subject_label,
            'changes' => $this->changes,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
