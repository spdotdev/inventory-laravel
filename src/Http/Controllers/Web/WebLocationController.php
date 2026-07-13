<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spdotdev\Inventory\Enums\LocationDeleteStrategy;
use Spdotdev\Inventory\Http\Requests\LocationRequest;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\HierarchyDeleter;

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
        // MUST go through HierarchyDeleter. A bare $location->delete() is now a
        // soft delete, which does NOT fire the ON DELETE CASCADE — the shelves
        // and products would survive as unreachable, un-purgeable orphans.
        //
        // The web UI has no strategy picker, so it keeps its historical
        // semantics: deleting a location deletes what is in it. The difference
        // is that it is now soft and batched, so it can be restored.
        HierarchyDeleter::deleteLocation(
            $household,
            $location,
            (string) Str::uuid(),
            LocationDeleteStrategy::DeleteContents,
            null,
        );

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->with('status', __('Location deleted.'));
    }
}
