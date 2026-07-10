<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
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

    /** Same tenancy rule as the API's household.member middleware: 404, never 403. */
    private function authorizeMember(Request $request, Household $household): void
    {
        $isMember = $household->users()
            ->whereKey($request->user('inventory')?->getKey())
            ->exists();

        abort_unless($isMember, 404);
    }
}
