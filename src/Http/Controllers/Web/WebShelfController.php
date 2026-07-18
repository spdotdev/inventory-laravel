<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Enums\ShelfDeleteStrategy;
use Spdotdev\Inventory\Events\HouseholdChanged;
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
        $data = $request->validated();
        // Same convention as the API: shelves created without a position append
        // after the location's current last shelf.
        $data['position'] ??= ((int) $location->shelves()->max('position')) + 1;
        $location->shelves()->create($data);

        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Shelf added.'));
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

    public function destroy(Request $request, Household $household, StorageLocation $location, Shelf $shelf): RedirectResponse
    {
        // Same rule as the API (ShelfController::destroy): the Unsorted shelf
        // exists to hold products the user chose to KEEP, so deleting it
        // while still occupied would strand the very products it protects.
        // The web UI has no JSON error surface, so flash back an error
        // instead of the API's 422 — matches the idiom used elsewhere on this
        // surface (e.g. WebHouseholdController::join's back()->withErrors()).
        if ($shelf->is_system && $shelf->products()->exists()) {
            return back()->withErrors(['shelf' => __('The Unsorted shelf still holds products. Move them first.')]);
        }

        // H5: the web form now offers move-away (unsort_products) as a
        // non-destructive alternative to the historical delete_products
        // default. move_products is deliberately NOT offered on the web — it
        // needs a target-shelf picker, which is disproportionate for a
        // thin-Blade form; Android's LOCKED delete dialog still exposes it.
        // No strategy param at all (empty shelf, or a pre-existing client
        // hitting this route) keeps the historical delete-everything default
        // for full backward compatibility.
        $strategy = $request->filled('strategy')
            ? $this->validatedStrategy($request)
            : ShelfDeleteStrategy::DeleteProducts;

        // MUST go through HierarchyDeleter — see WebLocationController::destroy
        // for why a bare $shelf->delete() would silently orphan its products.
        HierarchyDeleter::deleteShelf(
            $household,
            $shelf,
            (string) Str::uuid(),
            $strategy,
            null,
        );

        return redirect()
            ->route('inventory.web.locations.show', [$household, $location])
            ->with('status', __('Shelf deleted.'));
    }

    /**
     * @throws ValidationException when the strategy value is not one of the
     *                             two options the web form offers
     */
    private function validatedStrategy(Request $request): ShelfDeleteStrategy
    {
        $validated = $request->validate([
            'strategy' => ['required', Rule::in([
                ShelfDeleteStrategy::UnsortProducts->value,
                ShelfDeleteStrategy::DeleteProducts->value,
            ])],
        ]);

        return ShelfDeleteStrategy::from($validated['strategy']);
    }
}
