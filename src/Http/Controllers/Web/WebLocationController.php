<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\DeleteLocationRequest;
use Spdotdev\Inventory\Http\Requests\LocationRequest;
use Spdotdev\Inventory\Http\Requests\ReorderRequest;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\HierarchyDeleter;
use Spdotdev\Inventory\Support\Reorderer;

/**
 * Web CRUD for storage locations (Phase 2 stage 2). Tenancy is enforced by the
 * shared `household.member` middleware + scoped bindings on the route group —
 * the same rules as /api/v1. Validation reuses the API's LocationRequest.
 */
class WebLocationController extends Controller
{
    public function store(LocationRequest $request, Household $household): RedirectResponse
    {
        // Fold-in (web parity T3): this action mutates household structure the
        // same way Api\LocationController::store does, which HAS carried this
        // gate since the roles release — the web twin never had it, letting a
        // plain Member create storage via the web while the API and the web's
        // own update()/reorder() correctly required restructure. See
        // WebShelfController::store/destroy for the matching fix.
        Gate::authorize('restructure', $household);

        $household->locations()->create($request->validated());

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->withFragment('locations')
            ->with('status', __('Location added.'));
    }

    /**
     * Web twin of Api\LocationController::reorder — same Reorderer writer,
     * same `restructure` gate. Two callers hit this route:
     *  - Alpine (household.blade.php): PATCHes the full ids[] list as JSON
     *    after an optimistic swap; Accept: application/json (set by
     *    web-feedback.js) drives the JSON branch below.
     *  - The non-JS fallback: a plain form (`@method('PATCH')` spoofed POST)
     *    per row, whose hidden ids[] already encode the swapped order —
     *    computed server-side in the Blade view — so this single endpoint
     *    serves both without any direction/id branching logic here.
     */
    public function reorder(ReorderRequest $request, Household $household): RedirectResponse|JsonResponse
    {
        Gate::authorize('restructure', $household);

        Reorderer::locations($household, $request->ids());

        HouseholdChanged::dispatch((int) $household->getKey());

        if ($request->wantsJson()) {
            return response()->json(['message' => __('Order saved.')]);
        }

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->withFragment('locations')
            ->with('status', __('Order saved.'));
    }

    /**
     * Web twin of Api\LocationController::update (rename / retype) — same
     * LocationRequest, same restructure gate. Closes the parity gap where a
     * web-only user's sole recourse for a typo'd location name was
     * delete-and-recreate.
     */
    public function update(LocationRequest $request, Household $household, StorageLocation $location): RedirectResponse
    {
        Gate::authorize('restructure', $household);

        $location->update($request->validated());

        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Location updated.'));
    }

    public function show(Household $household, StorageLocation $location): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.location', [
            'household' => $household,
            'location' => $location,
            // is_system first, matching ShelfController::index — otherwise this
            // surface can show Unsorted FIRST, breaking "Unsorted always sorts
            // last" for the one client that reads this order.
            'shelves' => $location->shelves()->with('products')->orderBy('is_system')->orderBy('position')->get(),
            // Web parity T3: move-target picker for the shelf/location delete
            // dialogs. Household-wide, not location-scoped — HierarchyDeleter
            // itself is the only place a target actually gets validated
            // (same household, not self, not system), matching the API.
            'allShelves' => $household->shelves()->where('inventory_shelves.is_system', false)->with('location')->orderBy('name')->get(),
            'otherLocations' => $household->locations()->whereKeyNot($location->getKey())->orderBy('position')->orderBy('name')->get(),
        ]);
    }

    /**
     * Web parity T3: full delete-strategy support, mirroring
     * Api\LocationController::destroy exactly — DeleteLocationRequest is the
     * SAME Form Request the API uses, so the strategy/target-location
     * validation (contents-required, same-household, not-self) can never
     * drift between the two surfaces. Previously this action hard-coded
     * delete_contents as the only choice; the Alpine dialog in location.blade
     * now also offers move_contents with a target-location select.
     */
    public function destroy(DeleteLocationRequest $request, Household $household, StorageLocation $location): RedirectResponse
    {
        Gate::authorize('restructure', $household);

        // MUST go through HierarchyDeleter. A bare $location->delete() is now a
        // soft delete, which does NOT fire the ON DELETE CASCADE — the shelves
        // and products would survive as unreachable, un-purgeable orphans.
        $batchId = $request->batchId();

        HierarchyDeleter::deleteLocation(
            $household,
            $location,
            $batchId,
            $request->strategy(),
            $request->targetLocationId(),
            (int) $request->user()->getKey(),
        );

        // Web parity T4: the redirect carries the batch id so the layout can
        // upgrade the flash into an Undo toast — see
        // partials/undo-toast.blade.php. Session flash, not the response
        // body, because this is a plain form POST + redirect, not a fetch.
        return redirect()
            ->route('inventory.web.households.show', $household)
            ->with('status', __('Location deleted.'))
            ->with('undo', ['batch' => $batchId, 'household' => (int) $household->getKey()]);
    }
}
