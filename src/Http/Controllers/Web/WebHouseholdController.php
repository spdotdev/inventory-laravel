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
use Spdotdev\Inventory\Http\Requests\UpdateHouseholdRequest;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Support\HouseholdExport;
use Spdotdev\Inventory\Support\InviteQr;

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

        $household = Household::query()->create([
            'name' => $data['name'],
            'join_code' => Household::generateUniqueJoinCode(),
        ]);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->with('status', __('Household created.'));
    }

    public function join(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        /** @var User $user */
        $user = $request->user('inventory');

        $household = Household::query()->where('join_code', $data['code'])->first();
        if ($household === null) {
            return back()->withErrors(['code' => __('No household with that join code.')]);
        }
        if (! $household->users()->whereKey($user->getKey())->exists()) {
            $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        }

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->with('status', __('Joined :name.', ['name' => $household->name]));
    }

    public function show(Request $request, Household $household): View
    {
        $this->authorizeMember($request, $household);

        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.household', [
            'household' => $household,
            'members' => $household->users()->get(),
            'locations' => $household->locations()->withCount('shelves')->get(),
            'inviteLink' => $link = 'https://'.config('inventory.domain').'/join/'.$household->join_code,
            'inviteQrSvg' => InviteQr::svg($link),
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
        $household->users()->detach($user->getKey());

        return redirect()
            ->route('inventory.web.households')
            ->with('status', __('You left :name.', ['name' => $household->name]));
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

        return back()->with('status', 'Member role updated.');
    }

    public function removeMember(Request $request, Household $household, User $user): RedirectResponse
    {
        $this->authorizeMember($request, $household);
        Gate::authorize('manageMembers', $household);

        $targetRole = $household->roleOf($user);
        abort_if($targetRole === null, 404);
        abort_if($targetRole === 'owner', 403);

        $household->users()->detach($user->getKey());

        return back()->with('status', 'Member removed.');
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
        abort_if($newOwner->getKey() === $currentOwner->getKey(), 422, "You're already the owner.");

        DB::transaction(function () use ($household, $newOwner, $currentOwner) {
            $household->users()->updateExistingPivot($newOwner->getKey(), ['role' => 'owner']);
            $household->users()->updateExistingPivot($currentOwner->getKey(), ['role' => 'admin']);
        });

        return back()->with('status', 'Ownership transferred.');
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
