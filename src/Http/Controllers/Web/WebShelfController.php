<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Spdotdev\Inventory\Enums\ShelfDeleteStrategy;
use Spdotdev\Inventory\Http\Requests\ShelfRequest;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\HierarchyDeleter;

/** Web CRUD for shelves (Phase 2 stage 2); tenancy + validation as in the API. */
class WebShelfController extends Controller
{
    public function store(ShelfRequest $request, Household $household, StorageLocation $location): RedirectResponse
    {
        $data = $request->validated();
        // Same convention as the API: shelves created without a position append
        // after the location's current last shelf.
        $data['position'] ??= ((int) $location->shelves()->max('position')) + 1;
        $location->shelves()->create($data);

        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Shelf added.'));
    }

    public function destroy(Household $household, StorageLocation $location, Shelf $shelf): RedirectResponse
    {
        // MUST go through HierarchyDeleter — see WebLocationController::destroy
        // for why a bare $shelf->delete() would silently orphan its products.
        //
        // The web UI has no strategy picker, so it keeps its historical
        // semantics: deleting a shelf deletes what is on it. The difference is
        // that it is now soft and batched, so it can be restored.
        HierarchyDeleter::deleteShelf(
            $household,
            $shelf,
            (string) Str::uuid(),
            ShelfDeleteStrategy::DeleteProducts,
            null,
        );

        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Shelf deleted.'));
    }
}
