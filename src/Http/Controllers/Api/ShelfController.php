<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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

        $ids = $request->ids();
        // The Unsorted shelf is excluded on both sides of this check: it is
        // never draggable and always sorts last via is_system, so its position
        // is meaningless and the client never sends its id. Scoping both counts
        // to non-system shelves means (a) a payload that omits it still passes
        // completeness, and (b) a payload that DOES include it is rejected —
        // its id can't be found among non-system $owned, so the counts mismatch.
        $owned = $location->shelves()->where('is_system', false)->whereKey($ids)->pluck('id')->all();
        $total = $location->shelves()->where('is_system', false)->count();

        // Every id must be a live, non-system shelf of THIS location AND every
        // live non-system shelf must be present — see LocationController::reorder
        // for why a partial list is just as dangerous as a foreign one.
        if (count($owned) !== count($ids) || $total !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['The list must contain every shelf in this location, and only those.'],
            ]);
        }

        DB::transaction(function () use ($ids, $location) {
            foreach ($ids as $position => $id) {
                $location->shelves()->whereKey($id)->update(['position' => $position]);
            }
        });

        HouseholdChanged::dispatch((int) $household->getKey());

        return $this->index($household, $location);
    }
}
