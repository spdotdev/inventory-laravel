<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\DeleteLocationRequest;
use Spdotdev\Inventory\Http\Requests\LocationRequest;
use Spdotdev\Inventory\Http\Requests\ReorderRequest;
use Spdotdev\Inventory\Http\Resources\LocationResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\HierarchyDeleter;
use Spdotdev\Inventory\Support\Reorderer;

/**
 * Storage locations within a household. Scoped route-model binding guarantees
 * {location} belongs to {household}; household.member guarantees the caller is
 * a member. Mismatches resolve to 404.
 */
class LocationController
{
    public function index(Household $household): AnonymousResourceCollection
    {
        // withCount() folds both tallies into the single locations query via
        // subselects, so LocationResource can expose shelf_count/product_count
        // to every row here without an N+1 (see LocationResource::toArray()).
        // 'shelvesWithContents as shelf_count' reuses the exact relation
        // DeleteLocationRequest::locationHasContents() queries, so the two can
        // never drift apart; 'products' is StorageLocation's HasManyThrough
        // across all of a location's shelves.
        //
        // Manual order wins; name is only the tie-break for locations that have
        // never been dragged (they all sit at position 0).
        return LocationResource::collection(
            $household->locations()
                ->withCount(['shelvesWithContents as shelf_count', 'products'])
                ->orderBy('position')->orderBy('name')->get(),
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

        // Validation + the all-or-nothing transaction live in Reorderer,
        // shared with Web\WebLocationController::reorder so the two surfaces
        // can never drift on completeness/foreign-id rules.
        Reorderer::locations($household, $request->ids());

        // Query-builder updates fire no Eloquent events, so the observer never
        // sees this. Ping explicitly or other members' lists go stale.
        HouseholdChanged::dispatch((int) $household->getKey());

        return $this->index($household);
    }
}
