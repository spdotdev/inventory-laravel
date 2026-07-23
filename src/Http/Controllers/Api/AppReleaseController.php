<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Spdotdev\Inventory\Http\Requests\StoreAppReleaseRequest;
use Spdotdev\Inventory\Http\Requests\UpdateAppReleaseRequest;
use Spdotdev\Inventory\Http\Resources\AppReleaseResource;
use Spdotdev\Inventory\Models\AppRelease;

class AppReleaseController
{
    public function latest(): JsonResponse
    {
        $release = AppRelease::latestPublished();

        return response()->json(['data' => $release ? new AppReleaseResource($release) : null]);
    }

    public function index(): JsonResponse
    {
        $releases = AppRelease::orderByDesc('version_code')->get();

        return response()->json(['data' => AppReleaseResource::collection($releases)]);
    }

    public function store(StoreAppReleaseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $publish = (bool) ($data['publish'] ?? false);
        unset($data['publish']);
        $data['published_at'] = $publish ? now() : null;

        $release = AppRelease::create($data);

        return response()->json(['data' => new AppReleaseResource($release)], 201);
    }

    public function update(UpdateAppReleaseRequest $request, AppRelease $appRelease): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('publish', $data)) {
            $data['published_at'] = $data['publish'] ? now() : null;
            unset($data['publish']);
        }

        $appRelease->update($data);

        return response()->json(['data' => new AppReleaseResource($appRelease->fresh())]);
    }
}
