<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\JoinHouseholdRequest;
use Spdotdev\Inventory\Http\Requests\StoreHouseholdRequest;
use Spdotdev\Inventory\Http\Requests\UpdateHouseholdRequest;
use Spdotdev\Inventory\Http\Resources\HouseholdResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Support\HouseholdExport;

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

        // The creator is the household's Owner (single-Owner invariant). The
        // pivot column default is 'member' — omitting the role here shipped a
        // real bug where every new household had no owner at all and its
        // creator couldn't restructure their own storage.
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        return (new HouseholdResource($household))->response()->setStatusCode(201);
    }

    public function join(JoinHouseholdRequest $request): JsonResponse
    {
        $user = $this->user($request);

        /** @var array{code: string} $data */
        $data = $request->validated();

        $household = Household::query()->where('join_code', $data['code'])->first();

        abort_if($household === null, 404, 'Invalid join code.');

        // Idempotent: joining a household you're already in is a no-op. New
        // joiners land as 'member' (the pivot column default) — never a role
        // parameter from the request.
        $household->users()->syncWithoutDetaching([$user->getKey() => ['joined_at' => now()]]);

        // Heal an owner-less household (single-Owner invariant): normally
        // impossible, but the artisan command can create a household with no
        // members, and the roles rollout briefly shipped owner-less creates.
        // First member to arrive becomes Owner — the backfill's rule.
        if (! $household->hasOwner()) {
            $household->users()->updateExistingPivot($user->getKey(), ['role' => 'owner']);
        }

        return (new HouseholdResource($household))->response();
    }

    /**
     * Rename and/or theme a household (Phase 2). Owner/Admin only since roles
     * shipped — the design spec puts "edit household theme" under
     * `restructure`, and the Android client hides these controls from a plain
     * Member; the server has to actually enforce what the UI implies.
     * Color/icon are palette keys; explicit null clears back to the
     * client-derived default.
     */
    public function update(UpdateHouseholdRequest $request, Household $household): JsonResponse
    {
        Gate::authorize('restructure', $household);

        $household->update($request->validated());

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

    /**
     * Download the household as a versioned JSON document (member-gated, like
     * every other household route). Pretty-printed: an export exists to be
     * read and archived by a person, not parsed by the app.
     */
    public function export(Request $request, Household $household): JsonResponse
    {
        return response()->json(
            HouseholdExport::build($household),
            200,
            ['Content-Disposition' => 'attachment; filename="'.HouseholdExport::filename($household).'"'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function leave(Request $request, Household $household): JsonResponse
    {
        $user = $this->user($request);

        // A household can never end up with zero owners — the sole owner has to
        // transfer ownership before they can leave. See the roles design spec.
        if ($household->roleOf($user) === 'owner') {
            abort(409, 'Transfer ownership before leaving this household.');
        }

        $household->users()->detach($user->getKey());

        // If that was the last member, the household + its whole location→shelf→
        // product tree would otherwise survive with zero members — unreachable by
        // anyone (tenancy 404s non-members), dead data that only grows. Delete it;
        // ON DELETE CASCADE cleans the tree, matching the hard-delete posture.
        if ($household->users()->count() === 0) {
            $household->delete();
        } else {
            // Membership changed under the remaining members' feet — detach()
            // is a pivot write, so no Eloquent model event fires and the
            // observers stay silent. Ping the household channel explicitly,
            // same reasoning as the reorder endpoints.
            HouseholdChanged::dispatch((int) $household->getKey());
        }

        return response()->json(['message' => 'Left the household.']);
    }

    /**
     * Owner-only, with a server-side typed-name confirmation: the request must
     * carry the household's exact name. Destroying a whole household (possibly
     * with other members' data in it) deserves friction the server enforces,
     * not just the UI. Hard delete — same posture as last-member leave();
     * ON DELETE CASCADE cleans the tree and the image-reclaim observer runs.
     */
    public function destroy(Request $request, Household $household): JsonResponse
    {
        Gate::authorize('delete', $household);

        $confirmed = $request->validate([
            'name' => ['required', 'string'],
        ]);

        if ($confirmed['name'] !== $household->name) {
            throw ValidationException::withMessages([
                'name' => ['Type the household name exactly to confirm deletion.'],
            ]);
        }

        $household->delete();

        return response()->json(['message' => 'Household deleted.']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
