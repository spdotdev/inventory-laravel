<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\HouseholdUserPivot;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Support\Like;

class AdminController
{
    // ─── Users ───────────────────────────────────────────────────────────────

    public function listUsers(): JsonResponse
    {
        // Capped like searchUsers/SearchController/WebSearchController elsewhere
        // in the codebase — an unbounded ->get() over the whole table doesn't
        // scale and this is the existing pagination convention, not a new one.
        $users = User::query()
            ->withCount('households')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (User $u) => $this->userPayload($u));

        return response()->json(['data' => $users]);
    }

    public function getUser(int $id): JsonResponse
    {
        $user = User::query()->with('households')->findOrFail($id);

        return response()->json([
            'data' => array_merge($this->userPayload($user), [
                'households' => $user->households->map(fn ($h) => [
                    'id' => $h->id,
                    'name' => $h->name,
                    'join_code' => $h->join_code,
                    'joined_at' => $h->pivot instanceof HouseholdUserPivot ? $h->pivot->joined_at : null,
                ]),
            ]),
        ]);
    }

    public function deleteUser(int $id): JsonResponse
    {
        $user = User::query()->with('households')->findOrFail($id);

        // Deleting a user detaches them from every household via the pivot's
        // cascadeOnDelete, unlike HouseholdController::leave() this has no
        // "transfer ownership first" gate to lean on. Heal the single-Owner
        // invariant per household the same way leave()'s last-member cleanup
        // does: promote another member, or if this was the sole member, the
        // household would otherwise survive ownerless and unreachable.
        foreach ($user->households as $household) {
            if ($household->roleOf($user) !== 'owner') {
                continue;
            }

            $nextOwner = $household->users()
                ->where('inventory_household_user.user_id', '!=', $user->getKey())
                ->orderBy('inventory_household_user.joined_at')
                ->first();

            if ($nextOwner) {
                $household->users()->updateExistingPivot($nextOwner->getKey(), ['role' => 'owner']);
            } else {
                $household->delete();
            }
        }

        $user->delete();

        return response()->json(['message' => "User {$id} deleted."]);
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $query = (string) $request->input('q', '');

        $escaped = Like::escape($query);

        $users = User::query()
            ->withCount('households')
            ->where(function ($q) use ($escaped) {
                $q->whereRaw("name LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                    ->orWhereRaw("email LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
            })
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (User $u) => $this->userPayload($u));

        return response()->json(['data' => $users]);
    }

    // ─── Households ──────────────────────────────────────────────────────────

    public function listHouseholds(): JsonResponse
    {
        // Same cap/convention as listUsers above.
        $households = Household::query()
            ->withCount(['users', 'locations', 'shelves'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Household $h) => $this->householdPayload($h));

        return response()->json(['data' => $households]);
    }

    public function getHousehold(int $id): JsonResponse
    {
        $household = Household::query()
            ->with(['users', 'locations.shelves'])
            ->withCount(['users', 'locations', 'shelves'])
            ->findOrFail($id);

        return response()->json([
            'data' => array_merge($this->householdPayload($household), [
                'members' => $household->users->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'joined_at' => $u->pivot instanceof HouseholdUserPivot ? $u->pivot->joined_at : null,
                ]),
                'locations' => $household->locations->map(fn ($loc) => [
                    'id' => $loc->id,
                    'name' => $loc->name,
                    'shelf_count' => $loc->shelves->count(),
                ]),
            ]),
        ]);
    }

    public function deleteHousehold(int $id): JsonResponse
    {
        $household = Household::query()->findOrFail($id);
        $household->delete();

        return response()->json(['message' => "Household {$id} deleted."]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'google_id' => $user->google_id,
            'avatar_url' => $user->avatar_url,
            'created_at' => $user->created_at,
            'households_count' => $user->households_count ?? null,
        ];
    }

    private function householdPayload(Household $household): array
    {
        return [
            'id' => $household->id,
            'name' => $household->name,
            'join_code' => $household->join_code,
            'created_at' => $household->created_at,
            'users_count' => $household->users_count ?? null,
            'locations_count' => $household->locations_count ?? null,
            'shelves_count' => $household->shelves_count ?? null,
        ];
    }
}
