<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\DeleteShelfRequest;
use Spdotdev\Inventory\Http\Requests\ReorderRequest;
use Spdotdev\Inventory\Http\Requests\ShelfRequest;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\HierarchyDeleter;
use Spdotdev\Inventory\Support\Reorderer;

/** Web CRUD for shelves (Phase 2 stage 2); tenancy + validation as in the API. */
class WebShelfController extends Controller
{
    public function store(ShelfRequest $request, Household $household, StorageLocation $location): RedirectResponse
    {
        // Fold-in (web parity T3): see WebLocationController::store — this
        // web twin was missing the restructure gate the API's store() has
        // carried since the roles release, letting a plain Member create
        // storage via the web.
        Gate::authorize('restructure', $household);

        $data = $request->validated();
        // Same convention as the API: shelves created without a position append
        // after the location's current last shelf.
        $data['position'] ??= ((int) $location->shelves()->max('position')) + 1;
        $location->shelves()->create($data);

        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Shelf added.'));
    }

    /**
     * Web twin of Api\ShelfController::update, rename only — reparenting
     * (location_id) stays API-only until a web gesture needs it. Same
     * ShelfRequest, same restructure gate, same system-shelf guard: the
     * Unsorted shelf is a fixed concept the clients localise off is_system,
     * so renaming it must be refused here exactly as in the API.
     */
    public function update(ShelfRequest $request, Household $household, StorageLocation $location, Shelf $shelf): RedirectResponse
    {
        Gate::authorize('restructure', $household);

        $data = $request->validated();

        if ($shelf->is_system && array_key_exists('name', $data)) {
            return redirect()
                ->route('inventory.web.locations.show', [$household, $location])
                ->withErrors(['name' => __('The Unsorted shelf cannot be renamed.')]);
        }

        $shelf->update(['name' => $data['name'] ?? $shelf->name]);

        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Shelf updated.'));
    }

    /** Web twin of Api\ShelfController::reorder — see WebLocationController::reorder for the shared-endpoint rationale (JS + non-JS). */
    public function reorder(ReorderRequest $request, Household $household, StorageLocation $location): RedirectResponse|JsonResponse
    {
        Gate::authorize('restructure', $household);

        Reorderer::shelves($location, $request->ids());

        HouseholdChanged::dispatch((int) $household->getKey());

        if ($request->wantsJson()) {
            return response()->json(['message' => __('Order saved.')]);
        }

        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Order saved.'));
    }

    /**
     * Web parity T3: full delete-strategy support (move_products included),
     * mirroring Api\ShelfController::destroy exactly — DeleteShelfRequest is
     * the SAME Form Request the API uses, so the strategy/target-shelf
     * validation (products-required, same-household, not-self) can never
     * drift between the two surfaces.
     */
    public function destroy(DeleteShelfRequest $request, Household $household, StorageLocation $location, Shelf $shelf): RedirectResponse
    {
        Gate::authorize('restructure', $household);

        // Same rule as the API (ShelfController::destroy): the Unsorted shelf
        // exists to hold products the user chose to KEEP, so deleting it
        // while still occupied would strand the very products it protects.
        // The web UI has no JSON error surface, so flash back an error
        // instead of the API's 422 — matches the idiom used elsewhere on this
        // surface (e.g. WebHouseholdController::join's back()->withErrors()).
        // Dead in practice (the UI never renders a delete control for the
        // system shelf), kept as defence in depth against a direct POST.
        if ($shelf->is_system && $shelf->products()->exists()) {
            return back()->withErrors(['shelf' => __('The Unsorted shelf still holds products. Move them first.')]);
        }

        // MUST go through HierarchyDeleter — see WebLocationController::destroy
        // for why a bare $shelf->delete() would silently orphan its products.
        $batchId = $request->batchId();

        HierarchyDeleter::deleteShelf(
            $household,
            $shelf,
            $batchId,
            $request->strategy(),
            $request->targetShelfId(),
        );

        // Web parity T4: see WebLocationController::destroy for the undo-flash rationale.
        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Shelf deleted.'))
            ->with('undo', ['batch' => $batchId, 'household' => (int) $household->getKey()]);
    }
}
