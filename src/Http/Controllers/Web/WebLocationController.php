<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Spdotdev\Inventory\Http\Requests\LocationRequest;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Web CRUD for storage locations (Phase 2 stage 2). Tenancy is enforced by the
 * shared `household.member` middleware + scoped bindings on the route group —
 * the same rules as /api/v1. Validation reuses the API's LocationRequest.
 */
class WebLocationController extends Controller
{
    public function store(LocationRequest $request, Household $household): RedirectResponse
    {
        $household->locations()->create($request->validated());

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->with('status', __('Location added.'));
    }

    public function show(Household $household, StorageLocation $location): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.location', [
            'household' => $household,
            'location' => $location,
            'shelves' => $location->shelves()->with('products')->orderBy('position')->get(),
        ]);
    }

    public function destroy(Household $household, StorageLocation $location): RedirectResponse
    {
        // TODO(Task 6b): must route through HierarchyDeleter — this soft-deletes
        // the location and orphans its shelves/products.
        $location->delete();

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->with('status', __('Location deleted.'));
    }
}
