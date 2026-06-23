<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Auth\GoogleIdTokenVerifier;
use Spdotdev\Inventory\Http\Requests\LoginRequest;
use Spdotdev\Inventory\Http\Requests\RegisterRequest;
use Spdotdev\Inventory\Http\Resources\UserResource;
use Spdotdev\Inventory\Models\User;

class AuthController
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return $this->tokenResponse($user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || $user->password === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return $this->tokenResponse($user);
    }

    public function google(Request $request, GoogleIdTokenVerifier $verifier): JsonResponse
    {
        /** @var array{id_token: string} $data */
        $data = $request->validate(['id_token' => ['required', 'string']]);

        $claims = $verifier->verify($data['id_token']);

        if ($claims === null) {
            return response()->json(['message' => 'Invalid Google token.'], 401);
        }

        $user = User::query()->where('google_id', $claims['sub'])->first()
            ?? User::query()->where('email', $claims['email'])->first()
            ?? new User(['name' => $claims['name'] ?? $claims['email'], 'email' => $claims['email']]);

        $user->google_id = $claims['sub'];

        if ($claims['picture'] !== null && $user->avatar_url === null) {
            $user->avatar_url = $claims['picture'];
        }

        $user->save();

        return $this->tokenResponse($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('android')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], $status);
    }
}
