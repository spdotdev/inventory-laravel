<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\ReorderRequest;
use Spdotdev\Inventory\Http\Requests\ShelfRequest;
use Spdotdev\Inventory\Http\Resources\ShelfResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Shelves within a location. Scoped binding chains {shelf} ⊂ {location} ⊂
 * {household}; household.member guards membership. Mismatches resolve to 404.
 */
class ShelfController
{
    public function index(Household $household, StorageLocation $location): AnonymousResourceCollection
    {
        return ShelfResource::collection($location->shelves()->orderBy('position')->get());
    }

    public function store(ShelfRequest $request, Household $household, StorageLocation $location): JsonResponse
    {
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
        $shelf->update($request->validated());

        return new ShelfResource($shelf);
    }

    public function destroy(Household $household, StorageLocation $location, Shelf $shelf): JsonResponse
    {
        $shelf->delete();

        return response()->json(['message' => 'Shelf deleted.']);
    }

    /**
     * Rewrite every shelf's position within this location. See
     * LocationController::reorder — same contract, same all-or-nothing rule.
     */
    public function reorder(ReorderRequest $request, Household $household, StorageLocation $location): AnonymousResourceCollection
    {
        Gate::authorize('restructure', $household);

        $ids = $request->ids();
        $owned = $location->shelves()->whereKey($ids)->pluck('id')->all();

        if (count($owned) !== count($ids)) {
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
