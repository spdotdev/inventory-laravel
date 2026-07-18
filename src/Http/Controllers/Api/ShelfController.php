<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\DeleteShelfRequest;
use Spdotdev\Inventory\Http\Requests\ReorderRequest;
use Spdotdev\Inventory\Http\Requests\ShelfRequest;
use Spdotdev\Inventory\Http\Resources\ShelfResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\HierarchyDeleter;
use Spdotdev\Inventory\Support\Reorderer;

/**
 * Shelves within a location. Scoped binding chains {shelf} ⊂ {location} ⊂
 * {household}; household.member guards membership. Mismatches resolve to 404.
 */
class ShelfController
{
    public function index(Household $household, StorageLocation $location): AnonymousResourceCollection
    {
        // is_system first in the sort => Unsorted (is_system = true = 1) always
        // lands after the real shelves, whatever positions they hold.
        return ShelfResource::collection(
            $location->shelves()->withCount('products')->orderBy('is_system')->orderBy('position')->get(),
        );
    }

    public function store(ShelfRequest $request, Household $household, StorageLocation $location): JsonResponse
    {
        Gate::authorize('restructure', $household);

        $data = $request->validated();

        // The client only sends `name`, so without this every shelf lands at the
        // model default position 0 and index()'s orderBy('position') leaves the
        // tab/pager order undefined. Append new shelves after the current last.
        if (! array_key_exists('position', $data)) {
            $maxPosition = $location->shelves()->max('position');
            $data['position'] = $maxPosition === null ? 0 : $maxPosition + 1;
        }

        $shelf = $location->shelves()->create($data);

        return (new ShelfResource($shelf))->response()->setStatusCode(201);
    }

    public function show(Household $household, StorageLocation $location, Shelf $shelf): ShelfResource
    {
        return new ShelfResource($shelf);
    }

    public function update(ShelfRequest $request, Household $household, StorageLocation $location, Shelf $shelf): ShelfResource
    {
        Gate::authorize('restructure', $household);

        $data = $request->validated();

        // The Unsorted shelf is a fixed concept the client localises off
        // is_system. Letting a user rename it to "Bananas" would leave the app
        // showing a translated label that matches nothing in the database.
        if ($shelf->is_system && array_key_exists('name', $data)) {
            throw ValidationException::withMessages([
                'name' => ['The Unsorted shelf cannot be renamed.'],
            ]);
        }

        // Reparenting the Unsorted shelf is exactly the bug HierarchyDeleter's
        // move_contents strategy guards against one level up — moving it
        // as-is into a location that already has its own Unsorted shelf
        // produces two live is_system shelves there. Block the same edit at
        // this door too.
        if ($shelf->is_system && array_key_exists('location_id', $data)) {
            throw ValidationException::withMessages([
                'location_id' => ['The Unsorted shelf cannot be moved.'],
            ]);
        }

        // A Rule::exists in the request cannot see the household, so scope here:
        // without this a member could reparent a shelf into another household.
        if (array_key_exists('location_id', $data)) {
            $targetExists = $household->locations()->whereKey($data['location_id'])->exists();

            if (! $targetExists) {
                throw ValidationException::withMessages([
                    'location_id' => ['The selected location is not in this household.'],
                ]);
            }
        }

        $shelf->update($data);

        return new ShelfResource($shelf);
    }

    public function destroy(DeleteShelfRequest $request, Household $household, StorageLocation $location, Shelf $shelf): JsonResponse
    {
        Gate::authorize('restructure', $household);

        // Deleting an occupied Unsorted shelf would strand the very products it
        // exists to protect. Empty, it is disposable — unsortedShelf() rebuilds
        // it on demand.
        if ($shelf->is_system && $shelf->products()->exists()) {
            throw ValidationException::withMessages([
                'shelf' => ['The Unsorted shelf still holds products. Move them first.'],
            ]);
        }

        HierarchyDeleter::deleteShelf(
            $household,
            $shelf,
            $request->batchId(),
            $request->strategy(),
            $request->targetShelfId(),
        );

        return response()->json([
            'message' => 'Shelf deleted.',
            'deletion_batch_id' => $request->batchId(),
        ]);
    }

    /**
     * Rewrite every shelf's position within this location. See
     * LocationController::reorder — same contract, same all-or-nothing rule.
     */
    public function reorder(ReorderRequest $request, Household $household, StorageLocation $location): AnonymousResourceCollection
    {
        Gate::authorize('restructure', $household);

        // The Unsorted shelf is excluded by Reorderer on both sides of the
        // completeness check: it is never draggable and always sorts last via
        // is_system, so its position is meaningless and the client never
        // sends its id — see Reorderer::shelves' docblock. Shared with
        // Web\WebShelfController::reorder so the two surfaces can never drift.
        Reorderer::shelves($location, $request->ids());

        HouseholdChanged::dispatch((int) $household->getKey());

        return $this->index($household, $location);
    }
}
