<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\DeleteLocationRequest;
use Spdotdev\Inventory\Http\Requests\LocationRequest;
use Spdotdev\Inventory\Http\Requests\ReorderRequest;
use Spdotdev\Inventory\Http\Resources\LocationResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\HierarchyDeleter;

/**
 * Storage locations within a household. Scoped route-model binding guarantees
 * {location} belongs to {household}; household.member guarantees the caller is
 * a member. Mismatches resolve to 404.
 */
class LocationController
{
    public function index(Household $household): AnonymousResourceCollection
    {
        // Manual order wins; name is only the tie-break for locations that have
        // never been dragged (they all sit at position 0).
        return LocationResource::collection(
            $household->locations()->orderBy('position')->orderBy('name')->get(),
        );
    }

    public function store(LocationRequest $request, Household $household): JsonResponse
    {
        Gate::authorize('restructure', $household);

        $location = $household->locations()->create($request->validated());

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    public function show(Household $household, StorageLocation $location): LocationResource
    {
        return new LocationResource($location);
    }

    public function update(LocationRequest $request, Household $household, StorageLocation $location): LocationResource
    {
        Gate::authorize('restructure', $household);

        $location->update($request->validated());

        return new LocationResource($location);
    }

    public function destroy(DeleteLocationRequest $request, Household $household, StorageLocation $location): JsonResponse
    {
        Gate::authorize('restructure', $household);

        HierarchyDeleter::deleteLocation(
            $household,
            $location,
            $request->batchId(),
            $request->strategy(),
            $request->targetLocationId(),
        );

        return response()->json([
            'message' => 'Location deleted.',
            'deletion_batch_id' => $request->batchId(),
        ]);
    }

    /**
     * Rewrite every location's position from the ids the client sends, in one
     * transaction — a half-applied drag is worse than a rejected one.
     */
    public function reorder(ReorderRequest $request, Household $household): AnonymousResourceCollection
    {
        Gate::authorize('restructure', $household);

        $ids = $request->ids();
        $owned = $household->locations()->whereKey($ids)->pluck('id')->all();
        $total = $household->locations()->count();

        // Every id must be a live location of THIS household (rejects another
        // household's id, a deleted id, a typo) AND every live location must be
        // present (rejects a partial list). Either gap would let positions
        // collide, since they're assigned by array index 0..n-1.
        if (count($owned) !== count($ids) || $total !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['The list must contain every location in this household, and only those.'],
            ]);
        }

        DB::transaction(function () use ($ids, $household) {
            foreach ($ids as $position => $id) {
                $household->locations()->whereKey($id)->update(['position' => $position]);
            }
        });

        // Query-builder updates fire no Eloquent events, so the observer never
        // sees this. Ping explicitly or other members' lists go stale.
        HouseholdChanged::dispatch((int) $household->getKey());

        return $this->index($household);
    }
}
