<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Auth\GoogleIdTokenVerifier;
use Spdotdev\Inventory\Http\Requests\LoginRequest;
use Spdotdev\Inventory\Http\Requests\RegisterRequest;
use Spdotdev\Inventory\Http\Resources\UserResource;
use Spdotdev\Inventory\Models\User;

class AuthController
{
    /**
     * A valid bcrypt hash (of a throwaway string) at the default cost, used only
     * to equalize login timing when no real password hash is available (unknown
     * email or a Google-only account). Without it, `login()` returns measurably
     * faster for a non-existent account than for a wrong password — a
     * user-enumeration oracle, inconsistent with the non-enumerable
     * forgot-password + 404-everywhere posture. Never used to authenticate.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$2wtKLGVwS/ep14NDXHi1hecz8ZrOiWF1IpuMhLFC58A2lmm0/Olfe';

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

        // Always run a hash comparison — against the real hash, or a constant dummy
        // when the account is missing/passwordless — so both paths take the same
        // time and login can't be used to enumerate registered emails by timing.
        $hash = ($user !== null && $user->password !== null) ? $user->password : self::DUMMY_PASSWORD_HASH;
        $passwordMatches = Hash::check($credentials['password'], $hash);

        if ($user === null || $user->password === null || ! $passwordMatches) {
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

        // Normalize the Google email the same way register/login do (W13), so the
        // email fallback links to a case-normalized password account rather than
        // silently creating a duplicate on case-sensitive storage.
        $email = Str::lower($claims['email']);

        // Match by google_id, then fall back to email. The email-match links a
        // Google identity to a pre-existing (e.g. password) account — safe only
        // because the verifier guarantees a Google-verified email bound to our
        // own client ID, so the caller provably controls that address.
        $user = User::query()->where('google_id', $claims['sub'])->first()
            ?? User::query()->where('email', $email)->first()
            ?? new User(['name' => $claims['name'] ?? $email, 'email' => $email]);

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
