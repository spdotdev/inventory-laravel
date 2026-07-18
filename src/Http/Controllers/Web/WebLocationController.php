<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spdotdev\Inventory\Enums\LocationDeleteStrategy;
use Spdotdev\Inventory\Events\HouseholdChanged;
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
        ]);
    }

    public function destroy(Request $request, Household $household, StorageLocation $location): RedirectResponse
    {
        // H5: location-level delete has no unsort option (unsort is
        // shelf-level only — see LocationDeleteStrategy's docblock) and
        // move_contents is out of scope here too: it needs a target-location
        // picker, disproportionate for a thin-Blade form. delete_contents is
        // therefore the ONLY strategy the web form offers, so there is no
        // meaningful choice to make — but the destructive scope (shelf +
        // product counts) is still surfaced in the confirm copy in the Blade
        // view so this is an informed action, not a silent default.
        //
        // A strategy param is still validated when present (defence in
        // depth / a stable contract for the form), and its absence keeps the
        // historical delete-everything default for full backward
        // compatibility with any pre-existing caller of this route.
        if ($request->filled('strategy')) {
            $request->validate([
                'strategy' => ['required', Rule::in([LocationDeleteStrategy::DeleteContents->value])],
            ]);
        }

        // MUST go through HierarchyDeleter. A bare $location->delete() is now a
        // soft delete, which does NOT fire the ON DELETE CASCADE — the shelves
        // and products would survive as unreachable, un-purgeable orphans.
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
