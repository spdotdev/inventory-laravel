<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Support\Like;

/**
 * Global product search on the web (Phase 2) — the Blade twin of the API's
 * SearchController: same household scoping, same LIKE semantics, same 50-row
 * bound, but results link straight into the location pages.
 */
class WebSearchController extends Controller
{
    public function __invoke(Request $request, Household $household): View
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->whereHas('shelf.location', fn (Builder $query) => $query->where('household_id', $household->getKey()))
            ->when($q !== '', fn (Builder $query) => $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.Like::escape($q).'%']))
            ->with('shelf.location')
            ->orderBy('name')
            // Same X10 bound as the API: an empty query matches everything, so
            // never load an unbounded product catalog.
            ->limit(50)
            ->get();

        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.search', [
            'household' => $household,
            'q' => $q,
            'products' => $products,
        ]);
    }
}
