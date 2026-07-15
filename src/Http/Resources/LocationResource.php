<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * @mixin StorageLocation
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Hoisted to plain local reads so PHPStan actually checks the
        // property names — see ShelfResource::toArray() for the full
        // reasoning: Larastan exempts a property read from undefined-property
        // checking only when it sits directly inside a `??`/`isset()` guard,
        // which would otherwise silently swallow a typo'd property name (e.g.
        // shelf_count) with no static-analysis error.
        $shelfCount = $this->shelf_count;
        $productCount = $this->products_count;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'position' => $this->position,
            // The Android client needs this BEFORE it can even decide whether
            // to ask for a delete strategy: shelf_count > 0 is exactly
            // DeleteLocationRequest::locationHasContents()'s own rule — both
            // read through StorageLocation::shelvesWithContents(), which
            // excludes an empty system Unsorted shelf (see its docblock for
            // why). Keeping them on the same source means a client that skips
            // the strategy prompt when shelf_count == 0 can never get a 422
            // for a strategy-less delete.
            //
            // index() eager-loads both via withCount() to avoid an N+1 across
            // many locations; show()/store()/update() render a single
            // location, so the fallback query below is cheap and always
            // correct even when the counts weren't preloaded.
            'shelf_count' => $shelfCount ?? $this->shelvesWithContents()->count(),
            'product_count' => $productCount ?? $this->products()->count(),
        ];
    }
}
