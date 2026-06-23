<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        $shelf = $location->shelves()->create($request->validated());

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
}
