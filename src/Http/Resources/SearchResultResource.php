<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\Product;

/**
 * @mixin Product
 */
class SearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = $this->shelf->location;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'location' => $location->name,
            'shelf' => $this->shelf->name,
            'path' => $location->name.' › '.$this->shelf->name,
            // H4: additive — the raw 'shelf'/'path' strings above are the DB
            // literal for the system Unsorted shelf and shipped Android
            // builds parse them verbatim, so they stay untouched. This flag
            // lets the client gate on is_system and localize the name
            // itself, exactly like every other screen (shelfDisplayName()).
            'shelf_is_system' => $this->shelf->is_system,
            // Navigation IDs so the client can deep-link a hit straight to the
            // product (household › location › shelf › product). Without these the
            // Android search result is non-clickable — the whole point of search.
            'household_id' => $location->household_id,
            'location_id' => $location->getKey(),
            'shelf_id' => $this->shelf_id,
        ];
    }
}
