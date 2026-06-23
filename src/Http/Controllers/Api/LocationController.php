<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spdotdev\Inventory\Http\Requests\LocationRequest;
use Spdotdev\Inventory\Http\Resources\LocationResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Storage locations within a household. Scoped route-model binding guarantees
 * {location} belongs to {household}; household.member guarantees the caller is
 * a member. Mismatches resolve to 404.
 */
class LocationController
{
    public function index(Household $household): AnonymousResourceCollection
    {
        return LocationResource::collection($household->locations()->orderBy('name')->get());
    }

    public function store(LocationRequest $request, Household $household): JsonResponse
    {
        $location = $household->locations()->create($request->validated());

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    public function show(Household $household, StorageLocation $location): LocationResource
    {
        return new LocationResource($location);
    }

    public function update(LocationRequest $request, Household $household, StorageLocation $location): LocationResource
    {
        $location->update($request->validated());

        return new LocationResource($location);
    }

    public function destroy(Household $household, StorageLocation $location): JsonResponse
    {
        $location->delete();

        return response()->json(['message' => 'Location deleted.']);
    }
}
