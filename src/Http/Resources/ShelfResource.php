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
        // Hoisted to a plain local read so PHPStan actually checks the
        // property name: Larastan exempts a property read from undefined-
        // property checking only when it sits directly inside a `??` or
        // `isset()` guard, which is the idiomatic way to probe a
        // conditionally-set attribute — but that exemption also swallows a
        // typo (e.g. product_count) with no static-analysis error. Reading it
        // here first, as a plain read PHPStan DOES check, then falling back
        // separately below, keeps the typo catchable without losing the
        // fallback for show()/store()/update().
        $count = $this->products_count;

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
            'product_count' => $count ?? $this->products()->count(),
        ];
    }
}
