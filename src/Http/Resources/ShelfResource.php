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
            //
            // NOTE on the @property-read docblock on Shelf::$products_count:
            // it documents the attribute and protects any *direct* (non-??-
            // guarded) read of it elsewhere in the codebase, but it CANNOT make
            // PHPStan catch a typo (e.g. product_count) right here — PHPStan/
            // Larastan deliberately exempt property reads guarded by ?? or
            // isset() from undefined-property checking on Eloquent models, since
            // that is the idiomatic way to probe a conditionally-set attribute
            // (verified empirically; see task-4 fix report, Finding 4). Dropping
            // the ?? fallback to regain that check would break show()/store()/
            // update(), which never eager-load the count. This gap is structural
            // and not closeable without giving up the fallback.
            'product_count' => $this->products_count ?? $this->products()->count(),
        ];
    }
}
