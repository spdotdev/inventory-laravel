<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\Shelf;

/**
 * @mixin Shelf
 */
class ShelfResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'location_id' => $this->location_id,
            'is_system' => $this->is_system,
            // The client needs this BEFORE it can ask the delete question: the
            // strategy dialog says "3 shelves · 17 products", and without a count
            // it cannot tell the user what is at stake.
            //
            // index() eager-loads this via withCount('products') to avoid an N+1
            // across many shelves; show()/store()/update() render a single shelf
            // so the fallback ->count() there is cheap and always correct even
            // when the count wasn't preloaded.
            'product_count' => $this->products_count ?? $this->products()->count(),
        ];
    }
}
