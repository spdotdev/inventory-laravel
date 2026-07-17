<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\TransferOwnershipRequest;
use Spdotdev\Inventory\Http\Requests\UpdateMemberRoleRequest;
use Spdotdev\Inventory\Http\Resources\HouseholdMemberResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

/**
 * Membership management. Every action here is gated by `manageMembers` /
 * `transferOwnership` (HouseholdPolicy) — see the roles design spec for the
 * full invariant list this controller enforces (single owner, owner's row
 * untouchable except via transfer, 404-not-403 for a non-member target).
 */
class MemberController
{
    public function index(Household $household): AnonymousResourceCollection
    {
        return HouseholdMemberResource::collection($household->users()->orderBy('name')->get());
    }

    public function update(UpdateMemberRoleRequest $request, Household $household, User $user): JsonResponse
    {
        Gate::authorize('manageMembers', $household);

        $targetRole = $household->roleOf($user);
        abort_if($targetRole === null, 404);

        // The owner's own row is untouchable here — the only way it changes is
        // transfer-ownership. Without this a household could end up with zero
        // owners the instant an admin demotes the owner to member.
        abort_if($targetRole === 'owner', 403, "The owner's role can't be changed here — transfer ownership instead.");

        /** @var array{role: string} $data */
        $data = $request->validated();

        $household->users()->updateExistingPivot($user->getKey(), ['role' => $data['role']]);

        // Pivot writes fire no Eloquent events, so the observers stay silent —
        // ping the household channel explicitly so the affected member's other
        // devices refresh their role/capability flags (same reasoning as the
        // reorder endpoints).
        HouseholdChanged::dispatch((int) $household->getKey());

        return (new HouseholdMemberResource($household->users()->whereKey($user->getKey())->first()))->response();
    }

    public function destroy(Household $household, User $user): JsonResponse
    {
        Gate::authorize('manageMembers', $household);

        $targetRole = $household->roleOf($user);
        abort_if($targetRole === null, 404);
        abort_if($targetRole === 'owner', 403, 'The owner cannot be removed.');

        $household->users()->detach($user->getKey());

        HouseholdChanged::dispatch((int) $household->getKey());

        return response()->json(['message' => 'Member removed.']);
    }

    public function transferOwnership(TransferOwnershipRequest $request, Household $household): JsonResponse
    {
        Gate::authorize('transferOwnership', $household);

        /** @var array{user_id: int} $data */
        $data = $request->validated();

        $newOwner = User::find($data['user_id']);
        abort_if($newOwner === null, 404);

        $currentRole = $household->roleOf($newOwner);
        abort_if($currentRole === null, 404);

        $currentOwner = $request->user();
        abort_unless($currentOwner instanceof User, 401);

        // Transferring to yourself would run both pivot updates against the same
        // row inside the transaction below; the second call ('admin') overwrites
        // the first ('owner'), leaving the household with zero owners. That
        // violates the hard invariant: a household always has exactly one Owner.
        abort_if($newOwner->getKey() === $currentOwner->getKey(), 422, "You're already the owner.");

        DB::transaction(function () use ($household, $newOwner, $currentOwner) {
            $household->users()->updateExistingPivot($newOwner->getKey(), ['role' => 'owner']);
            $household->users()->updateExistingPivot($currentOwner->getKey(), ['role' => 'admin']);
        });

        HouseholdChanged::dispatch((int) $household->getKey());

        return response()->json(['message' => 'Ownership transferred.']);
    }
}
