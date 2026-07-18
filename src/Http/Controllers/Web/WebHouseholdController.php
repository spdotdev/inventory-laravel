<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\UpdateHouseholdRequest;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Support\HouseholdExport;
use Spdotdev\Inventory\Support\InviteQr;
use Spdotdev\Inventory\Support\OwnershipTransfer;
use Spdotdev\Inventory\Support\RecentlyDeleted;

/**
 * Household onboarding on the web (Phase 2): list/create/join/leave and the
 * invite code + link. Mirrors the API's HouseholdController semantics — same
 * models, same tenancy rules — rendered as Blade instead of JSON.
 */
class WebHouseholdController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user('inventory');

        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.households', [
            'households' => $user->households()->withCount(['users', 'locations'])->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        /** @var User $user */
        $user = $request->user('inventory');

        // One transaction: a failure after create() would otherwise leave an
        // owner-less orphan household with a minted join code (audit #4).
        $household = DB::transaction(function () use ($data, $user) {
            $household = Household::query()->create([
                'name' => $data['name'],
                'join_code' => Household::generateUniqueJoinCode(),
            ]);
            // The creator is the household's Owner (single-Owner invariant) — the
            // pivot column default is 'member', so the role must be explicit here.
            $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);

            return $household;
        });

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->with('status', __('Household created.'));
    }

    public function join(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        /** @var User $user */
        $user = $request->user('inventory');

        // Normalize before lookup: codes are minted uppercase (XXXX-XXXX), and
        // a pasted code often arrives with stray whitespace or lowercased
        // (audit #6).
        $code = strtoupper(trim($data['code']));

        $household = Household::query()->where('join_code', $code)->first();
        if ($household === null) {
            return back()->withErrors(['code' => __('No household with that join code.')]);
        }

        // Distinguish "already in" from a fresh join for honest feedback
        // (audit #3), and use the API's race-safe idempotent write instead of
        // check-then-attach (audit #2).
        $alreadyMember = $household->users()->whereKey($user->getKey())->exists();
        $household->users()->syncWithoutDetaching([$user->getKey() => ['joined_at' => now()]]);

        // Heal an owner-less household (single-Owner invariant) — same rule as
        // the API join endpoint: first member to arrive becomes Owner.
        if (! $household->hasOwner()) {
            $household->users()->updateExistingPivot($user->getKey(), ['role' => 'owner']);
        }

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->with('status', $alreadyMember
                ? __("You're already a member of :name.", ['name' => $household->name])
                : __('Joined :name.', ['name' => $household->name]));
    }

    public function show(Request $request, Household $household): View
    {
        $this->authorizeMember($request, $household);

        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.household', [
            'household' => $household,
            'members' => $household->users()->get(),
            // Manual order wins, name is the tie-break — same rule as
            // Api\LocationController::index, so a household reordered via one
            // surface shows the same order on the other.
            // 'products' added for T3's location delete-strategy dialog: the
            // summary line ("N shelves, M products") needs a product count
            // per location without an N+1; StorageLocation::products() is the
            // HasManyThrough across all of a location's shelves.
            // 'shelvesWithContents as shelves_count', not plain 'shelves': the
            // count must stay in lockstep with the API's shelf_count and with
            // DeleteLocationRequest::locationHasContents() (see that relation's
            // docblock) — a bare count includes an empty system Unsorted shelf,
            // showing "1 shelf" where Android shows 0 and forcing a delete
            // strategy the server doesn't actually require.
            'locations' => $household->locations()->withCount(['shelvesWithContents as shelves_count', 'products'])->orderBy('position')->orderBy('name')->get(),
            'inviteLink' => $link = 'https://'.config('inventory.domain').'/join/'.$household->join_code,
            'inviteQrSvg' => InviteQr::svg($link),
            // Web parity T4: "Recently deleted" section — restorable batches
            // within the retention window, regardless of which surface
            // (API/Android or web) minted them.
            'deletedBatches' => RecentlyDeleted::forHousehold($household),
        ]);
    }

    /** Download the household as JSON — the web twin of the API export route. */
    public function export(Request $request, Household $household): JsonResponse
    {
        $this->authorizeMember($request, $household);

        return response()->json(
            HouseholdExport::build($household),
            200,
            ['Content-Disposition' => 'attachment; filename="'.HouseholdExport::filename($household).'"'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** Rename + theme (Phase 2). Same validation as the API's PATCH endpoint. */
    public function update(UpdateHouseholdRequest $request, Household $household): RedirectResponse
    {
        $this->authorizeMember($request, $household);
        // Owner/Admin only, same gate as the API update() — the spec puts
        // rename/theme under `restructure`.
        Gate::authorize('restructure', $household);

        // The web form posts '' for "default"; ConvertEmptyStringsToNull has
        // already turned that into the null the API also uses for clearing.
        $household->update($request->validated());

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->with('status', __('Household updated.'));
    }

    public function leave(Request $request, Household $household): RedirectResponse
    {
        $this->authorizeMember($request, $household);

        /** @var User $user */
        $user = $request->user('inventory');

        // Mirror the API leave(): a household can never end up with zero
        // owners — the sole owner has to transfer ownership before leaving.
        // (This guard was on the API from the roles release but missing here.)
        if ($household->roleOf($user) === 'owner') {
            return back()->withErrors(['leave' => __('Transfer ownership before leaving this household.')]);
        }

        $household->users()->detach($user->getKey());

        // Same posture as the API: an emptied household is unreachable dead
        // data — delete it; otherwise ping the channel so remaining members'
        // clients refresh (detach() fires no Eloquent events).
        if ($household->users()->count() === 0) {
            $household->delete();
        } else {
            HouseholdChanged::dispatch((int) $household->getKey());
        }

        return redirect()
            ->route('inventory.web.households')
            ->with('status', __('You left :name.', ['name' => $household->name]));
    }

    /**
     * Owner-only; same server-side typed-name confirmation as the API.
     *
     * The web form field is `confirm_name`, not the API's `name` — the
     * household page also has a location-add form validating `name`, and a
     * shared field name would collide in the single global $errors bag
     * (GAP-4 L4). The API's `name` field itself is a shipped contract
     * (Android sends `name`) and is untouched.
     */
    public function destroy(Request $request, Household $household): RedirectResponse
    {
        $this->authorizeMember($request, $household);
        Gate::authorize('delete', $household);

        $data = $request->validate(['confirm_name' => ['required', 'string']]);
        if ($data['confirm_name'] !== $household->name) {
            return back()->withFragment('danger')
                ->withErrors(['confirm_name' => __('Type the household name exactly to confirm deletion.')]);
        }

        $household->delete();

        return redirect()
            ->route('inventory.web.households')
            ->with('status', __('Household deleted.'));
    }

    public function updateMemberRole(Request $request, Household $household, User $user): RedirectResponse
    {
        $this->authorizeMember($request, $household);
        Gate::authorize('manageMembers', $household);

        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'member'])]]);

        $targetRole = $household->roleOf($user);
        abort_if($targetRole === null, 404);
        abort_if($targetRole === 'owner', 403);

        $household->users()->updateExistingPivot($user->getKey(), ['role' => $data['role']]);

        // Pivot writes fire no Eloquent events — ping the channel explicitly
        // so the affected member's devices refresh their capability flags.
        HouseholdChanged::dispatch((int) $household->getKey());

        return back()->withFragment('members')->with('status', __('Member role updated.'));
    }

    public function removeMember(Request $request, Household $household, User $user): RedirectResponse
    {
        $this->authorizeMember($request, $household);
        Gate::authorize('manageMembers', $household);

        $targetRole = $household->roleOf($user);
        abort_if($targetRole === null, 404);
        abort_if($targetRole === 'owner', 403);

        $household->users()->detach($user->getKey());

        HouseholdChanged::dispatch((int) $household->getKey());

        return back()->withFragment('members')->with('status', __('Member removed.'));
    }

    public function transferOwnership(Request $request, Household $household): RedirectResponse
    {
        $this->authorizeMember($request, $household);
        Gate::authorize('transferOwnership', $household);

        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $newOwner = User::findOrFail($data['user_id']);
        abort_if($household->roleOf($newOwner) === null, 404);

        /** @var User $currentOwner */
        $currentOwner = $request->user('inventory');

        // Transferring to yourself would run both pivot updates against the same
        // row inside the transaction below; the second call ('admin') overwrites
        // the first ('owner'), leaving the household with zero owners. That
        // violates the hard invariant: a household always has exactly one Owner.
        // Redirect-back like every other validation failure on this surface —
        // an abort() here rendered a bare 422 page (audit #1).
        if ($newOwner->getKey() === $currentOwner->getKey()) {
            return back()->withFragment('members')
                ->withErrors(['user_id' => __("You're already the owner.")]);
        }

        // Conditional demote-first transfer (OwnershipTransfer) — a double
        // submit racing another transfer must not crown two owners.
        if (! OwnershipTransfer::transfer($household, $newOwner, $currentOwner)) {
            return back()->withFragment('members')
                ->withErrors(['user_id' => __('Ownership has already been transferred.')]);
        }

        HouseholdChanged::dispatch((int) $household->getKey());

        return back()->withFragment('members')->with('status', __('Ownership transferred.'));
    }

    /** Same tenancy rule as the API's household.member middleware: 404, never 403. */
    private function authorizeMember(Request $request, Household $household): void
    {
        $isMember = $household->users()
            ->whereKey($request->user('inventory')?->getKey())
            ->exists();

        abort_unless($isMember, 404);
    }
}
