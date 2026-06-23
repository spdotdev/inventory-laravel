<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spdotdev\Inventory\Http\Resources\SearchResultResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;

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

        $products = Product::query()
            ->whereHas('shelf.location', fn (Builder $query) => $query->where('household_id', $household->getKey()))
            ->when($q !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$q.'%'))
            ->with('shelf.location')
            ->orderBy('name')
            ->get();

        return SearchResultResource::collection($products);
    }
}
