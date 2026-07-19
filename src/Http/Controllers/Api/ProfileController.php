<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spdotdev\Inventory\Http\Requests\UpdatePasswordRequest;
use Spdotdev\Inventory\Http\Requests\UpdateProfileRequest;
use Spdotdev\Inventory\Http\Resources\UserResource;
use Spdotdev\Inventory\Models\User;

/**
 * Self-service account management for the authenticated caller — update own
 * name/email, or change password with current-password confirmation. Distinct
 * from AdminController (operator-only, static bearer token) and from the
 * enumeration-safe "forgot password" flow (unauthenticated, doesn't know the
 * current password).
 */
class ProfileController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->fill($request->validated());
        $user->save();

        return response()->json(['data' => new UserResource($user)]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // The 'hashed' cast on User::password hashes this on assignment.
        $user->password = $request->validated()['password'];
        $user->save();

        return response()->json(['message' => 'Password updated.']);
    }
}
