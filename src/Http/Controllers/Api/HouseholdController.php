<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spdotdev\Inventory\Http\Requests\JoinHouseholdRequest;
use Spdotdev\Inventory\Http\Requests\StoreHouseholdRequest;
use Spdotdev\Inventory\Http\Resources\HouseholdResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

class HouseholdController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        return HouseholdResource::collection($user->households()->orderBy('name')->get());
    }

    public function store(StoreHouseholdRequest $request): JsonResponse
    {
        $user = $this->user($request);

        /** @var array{name: string} $data */
        $data = $request->validated();

        $household = Household::create([
            'name' => $data['name'],
            'join_code' => Household::generateUniqueJoinCode(),
        ]);

        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return (new HouseholdResource($household))->response()->setStatusCode(201);
    }

    public function join(JoinHouseholdRequest $request): JsonResponse
    {
        $user = $this->user($request);

        /** @var array{code: string} $data */
        $data = $request->validated();

        $household = Household::query()->where('join_code', $data['code'])->first();

        abort_if($household === null, 404, 'Invalid join code.');

        // Idempotent: joining a household you're already in is a no-op.
        $household->users()->syncWithoutDetaching([$user->getKey() => ['joined_at' => now()]]);

        return (new HouseholdResource($household))->response();
    }

    public function invite(Request $request, Household $household): JsonResponse
    {
        // Always https for the shared invite link (the web fallback served by
        // the `inventory.join` route lives at this exact path).
        $link = 'https://'.config('inventory.domain').'/join/'.$household->join_code;

        return response()->json([
            'code' => $household->join_code,
            'link' => $link,
        ]);
    }

    public function leave(Request $request, Household $household): JsonResponse
    {
        $user = $this->user($request);

        $household->users()->detach($user->getKey());

        return response()->json(['message' => 'Left the household.']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
