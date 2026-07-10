<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spdotdev\Inventory\Http\Resources\SearchResultResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Support\Like;

class SearchController
{
    /**
     * Global product search within a household. Results carry the location path
     * (location › shelf) + quantity. Scoped to the route household, which the
     * household.member middleware has already verified the caller belongs to.
     */
    public function __invoke(Request $request, Household $household): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q', ''));

        $escaped = Like::escape($q);

        $products = Product::query()
            ->whereHas('shelf.location', fn (Builder $query) => $query->where('household_id', $household->getKey()))
            ->when($q !== '', fn (Builder $query) => $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$escaped.'%']))
            ->with('shelf.location')
            ->orderBy('name')
            // Bound the result set (consistent with the admin + MCP searches) so a
            // large household — or an empty query, which matches everything — can't
            // load and serialize the entire product catalog (X10).
            ->limit(50)
            ->get();

        return SearchResultResource::collection($products);
    }
}
