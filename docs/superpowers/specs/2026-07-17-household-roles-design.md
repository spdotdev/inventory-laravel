# Household roles (Owner / Admin / Member) — Design

**Status:** approved, ready for planning
**Repos touched:** `inventory-laravel` (backend + web UI), `inventory-android`
**Canonical for:** both clients — this is a cross-repo spec, living in `inventory-laravel/docs/` per that repo's CLAUDE.md.

## Why

`HouseholdPolicy::restructure()` has existed since the 2026-07-13 storage-architecture-editing
work as a deliberate seam: every mutating storage-structure route (locations, shelves, restore)
already authorizes through it. Today it grants any member — "all members are equal in practice."
This spec is the roles model that seam was built for: Owner/Admin can restructure and manage
membership, Member can use the household (stock actions, search) but not reshape it or manage who's in it.

## Out of scope

- Per-location or per-shelf permissions — roles are household-wide only.
- Choosing a role at invite time — every invite-code/link join lands as Member; an Owner/Admin
  promotes afterward if needed.
- An audit log of role changes — no activity log exists anywhere in this app (deliberately cut,
  see both repos' CLAUDE.md Scope guardrails) and this doesn't reopen that.
- Per-household feature gating beyond `restructure`/`manageMembers`/`delete`/`transferOwnership` —
  no new permission dimensions beyond what's specified below.

## Data model

`inventory_household_user` (composite PK `household_id`+`user_id`, no surrogate key) gains one column:

```php
$table->enum('role', ['owner', 'admin', 'member'])->default('member');
```

**Backfill migration** (runs once, on existing production data): for each household, the member
with the earliest `joined_at` becomes `owner`; every other existing member becomes `admin`. This
was an explicit user decision — existing households had no recorded creator, and promoting
existing members to Admin rather than leaving them at Member preserves their current de-facto
"all equal, can restructure" capability except for the handful of owner-only actions (delete
household, transfer ownership, demote another owner).

New joins via `households/join` (invite code or link) always insert with `role = 'member'`
(the column default already does this — no request-side role parameter exists or is accepted).

## Backend authorization

**`HouseholdPolicy` gains three methods, `restructure()`'s body changes:**

```php
// CHANGED — was `return true` for any member.
public function restructure(User $user, Household $household): bool
{
    return $this->roleOf($user, $household) !== null
        && in_array($this->roleOf($user, $household), ['owner', 'admin'], true);
}

// NEW — gates role changes and member removal.
public function manageMembers(User $user, Household $household): bool
{
    return $this->restructure($user, $household); // same tier: owner or admin
}

// NEW — owner-only actions.
public function transferOwnership(User $user, Household $household): bool
{
    return $this->roleOf($user, $household) === 'owner';
}

public function delete(User $user, Household $household): bool
{
    return $this->roleOf($user, $household) === 'owner';
}
```

No existing call site changes — `restructure` is already wired into every mutating storage route
(`ShelfController`, `LocationController`, `RestoreController`). This is the entire reason that
seam was built as a policy method rather than an inline check.

**A household has exactly one Owner at all times.** The role is transferable (`transfer-ownership`
below), not multi-holder — this is a single-owner model, matching the "creator starts as Owner but
can hand it off" decision, not a "several owners" one.

**Membership invariants, enforced server-side (never trust the client):**

- **The Owner can't leave** (`households/{household}/leave`) while they're still Owner — a
  household can never end up with zero owners. Attempting this returns **409** ("transfer
  ownership first"), not a silent orphan or a misleading 200.
- **Nobody can demote or remove the Owner** via `PATCH`/`DELETE members/{user}` — those endpoints
  reject any attempt to change or remove the Owner's row (403). The *only* way the Owner stops
  being Owner is `transfer-ownership`, which atomically makes someone else Owner and demotes the
  caller to Admin in the same transaction — there's exactly one code path that changes who holds
  the role, so "can a household end up without an owner" only has to be proven safe once.
- **Only the Owner can call `transfer-ownership`.** An Admin can promote a Member to Admin or
  demote an Admin to Member, but has no path to becoming Owner except being handed it.
- **An Admin can remove any Member or Admin** (not the Owner, per above).

**New endpoints** (all under the existing `household.member` + `scopeBindings` group in
`routes/api.php`, alongside `locations`/`shelves`):

```
GET    households/{household}/members
PATCH  households/{household}/members/{user}         (body: { role: "admin"|"member" })
DELETE households/{household}/members/{user}
POST   households/{household}/transfer-ownership      (body: { user_id: <int> })
```

- `GET members` — any member may call it (visibility of the roster isn't restricted, same as
  `join_code` today). Returns `id`, `name`, `role`, `joined_at`.
- `PATCH members/{user}` — `Gate::authorize('manageMembers', $household)`, plus the "only an owner
  touches owner status" rule above enforced in the controller/form-request. Setting
  `role: "owner"` is **rejected here (422)** — becoming an owner only happens via
  `transfer-ownership`, so there's exactly one code path that mints a new owner and exactly one
  path (self-leave-after-transfer) that removes one, instead of two paths that both have to
  re-implement the same invariants.
- `DELETE members/{user}` — `Gate::authorize('manageMembers', $household)`; 403 if the target is
  an owner (see invariant above); 404 if the target isn't a member (tenancy-consistent with the
  rest of this app: never leak existence via 403).
- `POST transfer-ownership` — `Gate::authorize('transferOwnership', $household)`. Sets the target
  user's role to `owner` and the caller's role to `admin` in one transaction. The target must
  already be a member (404 otherwise, same tenancy rule).

**`HouseholdResource` changes** — adds the *caller's own* role and two derived booleans, so
neither client re-implements the role→capability mapping:

```php
'role' => $this->pivot->role ?? $this->users()->find($request->user()->id)?->pivot->role,
'can_restructure' => Gate::forUser($request->user())->allows('restructure', $this->resource),
'can_manage_members' => Gate::forUser($request->user())->allows('manageMembers', $this->resource),
```

(Exact accessor for "the caller's own pivot row" depends on how the household was loaded for this
request — implementer picks whichever avoids an N+1, consistent with how `join_code` is already
scoped to "this resource is only ever returned to members.")

A new `HouseholdMemberResource` wraps the `GET members` list: `id`, `name`, `role`, `joined_at`.

## Web UI

`WebHouseholdController::show()` already passes `'members' => $household->users()->get()` to the
view — extend that query to eager-load `role`, and the Blade view gains:
- A role badge per member row (Owner/Admin/Member).
- Promote/demote controls and a remove button, each `@can('manageMembers', $household)`; the
  Owner's own row shows neither — the only action available on it is "Transfer ownership" (below),
  and only to the Owner viewing their own row.
- A "Transfer ownership" action in the danger zone, `@can('transferOwnership', $household)`.

No new routes beyond mapping the four backend endpoints into `routes/web.php` under the existing
`household.member` group, following the pattern of every other web CRUD action in this repo
(session-guarded, same controllers' Gate calls, not duplicated authorization logic).

## Android

**Gating existing UI:** every edit-mode pencil (households list, locations, shelves) and the
household theme-edit page currently renders unconditionally for any member. It now renders only
when `HouseholdDto.can_restructure` is true. This mirrors, not duplicates, server enforcement — a
Member who somehow triggers a restructure call still gets a 403, which existing error-mapping
already turns into a message (`ErrorMapping.kt`); the UI gate is about not offering an action
that's guaranteed to fail, not the security boundary itself.

**New Members screen**, reached from the household edit page (same place the "Leave" danger-zone
action lives today):
- List of members: name, role badge, joined date.
- If `can_manage_members`: promote/demote and remove buttons on Member/Admin rows only — mirrors
  the web rule that the Owner's row shows neither.
- If the viewer's own role is `owner`: a "Transfer ownership" action, opening a member picker.
- Data layer: `MemberRepository` (`list`, `updateRole`, `remove`, `transferOwnership`), a
  `MemberDto`, request DTOs — same additive pattern as every other repository in this app
  (interface + Impl, registered in both `NetworkModule.kt` and the test module, wired into
  `SessionCleaner` if it caches anything per-account).
- `HouseholdDto` gains `role: String`, `can_restructure: Boolean`, `can_manage_members: Boolean` —
  **no client-side defaults on these two booleans** (follow this repo's `encodeDefaults = false`
  rule the same way every other DTO in this app does; the field must always be sent by the server,
  never silently assumed `true` by an old cached value).

**Leave flow:** the existing leave confirmation gains a 409 case — "you're the only owner; transfer
ownership first" — surfaced via the existing error-mapping fallback-string pattern, not a new
dialog type.

## Error handling

- 409 — last-owner leave/demote/remove attempt (backend invariant above).
- 422 — attempting to set `role: "owner"` via `PATCH members/{user}` (must use transfer-ownership).
- 403 — a Member calling any `restructure`-gated or `manageMembers`-gated endpoint; a non-owner
  calling `transfer-ownership`; anyone attempting to remove an owner via `DELETE members/{user}`.
- 404 — target user isn't a member of the household (tenancy-consistent, never a 403 that leaks
  membership existence).

## Testing

- **Backend (PHPUnit):** policy unit tests for all four `HouseholdPolicy` methods across all three
  roles; feature tests for each new endpoint (happy path + the last-owner 409 + the owner-promotion
  403-for-admin + the remove-owner 403); the backfill migration tested against a seeded multi-member
  household asserting earliest-`joined_at` gets `owner`.
- **Android (JVM unit + instrumented):** `MemberRepository` fakes; ViewModel tests for
  promote/demote/remove/transfer covering the "can't touch owner unless I am one" client-side gate
  (defense in depth, not the boundary); an instrumented flow test for the Members screen; a
  regression test asserting edit-mode pencils don't render when `can_restructure` is false (this
  is the exact class of bug the storage-architecture-editing branch shipped once already — see that
  repo's "Testing lessons").
- **Web:** feature tests for the four new routes mirroring the API's authorization tests, reusing
  the same `HouseholdPolicy` so there's one source of truth to test rather than two.
