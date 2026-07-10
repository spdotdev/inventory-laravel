<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Spdotdev\Inventory\Http\Requests\ShelfRequest;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

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
        $shelf->delete();

        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Shelf deleted.'));
    }
}
