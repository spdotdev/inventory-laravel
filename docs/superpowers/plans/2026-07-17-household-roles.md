# Household Roles (Owner/Admin/Member) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Activate the `HouseholdPolicy::restructure` seam with a real Owner/Admin/Member role model — backend enforcement, web UI, and Android UI — per the approved spec `docs/superpowers/specs/2026-07-17-household-roles-design.md`.

**Architecture:** One `role` column on `inventory_household_user`, backfilled (oldest member → owner, rest → admin). `HouseholdPolicy` grows from one method to four (`restructure`, `manageMembers`, `transferOwnership`, `delete`), all call sites elsewhere untouched. Four new API endpoints under the existing `household.member` group. `HouseholdResource` exposes the caller's own role + two derived booleans so neither client re-implements the role→capability mapping. Web and Android both consume that same resource shape.

**Tech Stack:** Backend — PHP 8.3, Laravel 13, Sanctum, PHPUnit, Pint, Larastan. Android — Kotlin, Compose, Hilt, Retrofit + kotlinx.serialization, JUnit4 + hand-written fakes.

**Depends on:** nothing outstanding — `HouseholdPolicy::restructure` and every route that authorizes against it already ships in production.

## Global Constraints

- Backend: Form Requests for validation, API Resources for responses, tenancy via `household.member` middleware + scoped bindings (copied verbatim from `inventory-laravel/CLAUDE.md`).
- Backend tests: RefreshDatabase + `Sanctum::actingAs`, base URL `http://inventory.test/api/v1` (copied from existing `HouseholdThemeTest`/`RestructurePolicyTest`).
- Android: repositories **throw**, ViewModels catch with `runCatching` + `Throwable.toUserMessage(fallback)`; a new repository-interface method needs a throwing default (`= throw UnsupportedOperationException(...)`) so existing fakes keep compiling; a new Api interface must be registered in **both** `di/NetworkModule.kt` and `androidTest/.../di/TestNetworkModule.kt`; a new PATCH/POST request DTO must not give properties defaults unless the field is deliberately "omit = leave alone" (the app's `Json` has `encodeDefaults = false`); mutations use `launchLoading { }`; any mutation that changes membership must call `hierarchyStore.refresh()` if it affects what the user can see; a new per-account cache must be wired into `SessionCleaner.clear()`; every new user-facing string goes in both `res/values/strings.xml` and `res/values-nl/strings.xml`; `ktlintCheck`/`detekt` are blocking CI gates, baseline-aware — new code must be clean (all copied verbatim from `inventory-android`'s storage-architecture-editing plan, which this plan follows the same conventions as).
- Single-owner invariant: a household always has exactly one Owner. The only way to change who holds it is `transfer-ownership`. `PATCH`/`DELETE members/{user}` always reject any attempt to touch the Owner's own row.

---

## Task 1: Backend — `role` column + backfill migration

**Files:**
- Create: `database/migrations/2026_07_17_000001_add_role_to_inventory_household_user_table.php`
- Create: `database/migrations/2026_07_17_000002_backfill_inventory_household_user_roles.php`
- Test: `tests/Feature/HouseholdRoleBackfillTest.php`

**Interfaces:**
- Produces: `inventory_household_user.role` column, values `'owner'|'admin'|'member'`, default `'member'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HouseholdRoleBackfillTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdRoleBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_memberships_default_to_member(): void
    {
        $user = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        $this->assertSame(
            'member',
            DB::table('inventory_household_user')
                ->where('household_id', $household->id)
                ->where('user_id', $user->id)
                ->value('role'),
        );
    }

    public function test_the_column_accepts_owner_and_admin(): void
    {
        $user = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Home', 'join_code' => 'BBBB-2222']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        $this->assertSame(
            'owner',
            DB::table('inventory_household_user')
                ->where('household_id', $household->id)
                ->where('user_id', $user->id)
                ->value('role'),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/HouseholdRoleBackfillTest.php`
Expected: FAIL — `role` column does not exist (SQL error on insert/select).

- [ ] **Step 3: Add the schema migration**

Create `database/migrations/2026_07_17_000001_add_role_to_inventory_household_user_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_household_user', function (Blueprint $table) {
            // Default 'member' matches the invite-join behaviour: every code/link
            // join lands as Member, never Owner/Admin (see the roles design spec).
            $table->enum('role', ['owner', 'admin', 'member'])->default('member')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_household_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
```

- [ ] **Step 4: Add the backfill migration**

Create `database/migrations/2026_07_17_000002_backfill_inventory_household_user_roles.php`. This runs once against existing production data: per household, the earliest `joined_at` row becomes `owner`, every other existing row becomes `admin` (user decision — preserves existing members' de-facto "can restructure" capability rather than demoting them to Member).

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $householdIds = DB::table('inventory_household_user')->distinct()->pluck('household_id');

        foreach ($householdIds as $householdId) {
            $ownerUserId = DB::table('inventory_household_user')
                ->where('household_id', $householdId)
                ->orderBy('joined_at')
                ->value('user_id');

            if ($ownerUserId === null) {
                continue;
            }

            DB::table('inventory_household_user')
                ->where('household_id', $householdId)
                ->update(['role' => 'admin']);

            DB::table('inventory_household_user')
                ->where('household_id', $householdId)
                ->where('user_id', $ownerUserId)
                ->update(['role' => 'owner']);
        }
    }

    public function down(): void
    {
        // Irreversible by design — there is no recorded "who used to be equal"
        // state to restore. Rolling back the schema migration (which drops the
        // column) is the actual undo path.
    }
};
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/HouseholdRoleBackfillTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Run the full gate**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_17_000001_add_role_to_inventory_household_user_table.php \
        database/migrations/2026_07_17_000002_backfill_inventory_household_user_roles.php \
        tests/Feature/HouseholdRoleBackfillTest.php
git commit -m "feat: add role column to household membership + backfill

New memberships default to 'member' (join-by-code always lands as Member).
Existing households get backfilled: earliest joined_at becomes owner, every
other existing member becomes admin, per the roles design spec."
```

---

## Task 2: Backend — `HouseholdPolicy` roles + `Household`/`User` model wiring

**Files:**
- Modify: `src/Policies/HouseholdPolicy.php`
- Modify: `src/Models/Household.php`, `src/Models/User.php`, `src/Models/HouseholdUserPivot.php`
- Test: `tests/Feature/RestructurePolicyTest.php` (extend), `tests/Feature/HouseholdPolicyRolesTest.php` (new)

**Interfaces:**
- Consumes: `inventory_household_user.role` (Task 1).
- Produces: `HouseholdPolicy::restructure(User, Household): bool`, `::manageMembers(User, Household): bool`, `::transferOwnership(User, Household): bool`, `::delete(User, Household): bool`. `Household::roleOf(User): ?string`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/RestructurePolicyTest.php` entirely (the old "any member restructures" assertion is no longer true):

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class RestructurePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithRole(Household $household, string $role): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => "U{$n}", 'email' => "u{$n}@example.test", 'password' => 'secret-password']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return $user;
    }

    public function test_an_owner_may_restructure(): void
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $owner = $this->memberWithRole($household, 'owner');

        $this->assertTrue(Gate::forUser($owner)->allows('restructure', $household));
    }

    public function test_an_admin_may_restructure(): void
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'BBBB-2222']);
        $admin = $this->memberWithRole($household, 'admin');

        $this->assertTrue(Gate::forUser($admin)->allows('restructure', $household));
    }

    public function test_a_member_may_not_restructure(): void
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'CCCC-3333']);
        $member = $this->memberWithRole($household, 'member');

        $this->assertFalse(Gate::forUser($member)->allows('restructure', $household));
    }

    public function test_a_non_member_may_not_restructure(): void
    {
        // household.member 404s a non-member before the policy ever runs; the
        // policy still denies them so the rule holds if that middleware is ever
        // removed from a route by mistake.
        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Private', 'join_code' => 'ZZZZ-9999']);

        $this->assertFalse(Gate::forUser($outsider)->allows('restructure', $household));
    }
}
```

Create `tests/Feature/HouseholdPolicyRolesTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdPolicyRolesTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithRole(Household $household, string $role): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => "U{$n}", 'email' => "u{$n}@example.test", 'password' => 'secret-password']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return $user;
    }

    public function test_manage_members_matches_restructure_owner_and_admin(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'AAAA-1111']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        $member = $this->memberWithRole($household, 'member');

        $this->assertTrue(Gate::forUser($owner)->allows('manageMembers', $household));
        $this->assertTrue(Gate::forUser($admin)->allows('manageMembers', $household));
        $this->assertFalse(Gate::forUser($member)->allows('manageMembers', $household));
    }

    public function test_only_the_owner_may_transfer_ownership_or_delete(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'BBBB-2222']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');

        $this->assertTrue(Gate::forUser($owner)->allows('transferOwnership', $household));
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $household));
        $this->assertFalse(Gate::forUser($admin)->allows('transferOwnership', $household));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $household));
    }

    public function test_household_role_of_returns_null_for_a_non_member(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'CCCC-3333']);
        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'secret-password']);

        $this->assertNull($household->roleOf($outsider));
    }

    public function test_household_role_of_returns_the_members_role(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'DDDD-4444']);
        $admin = $this->memberWithRole($household, 'admin');

        $this->assertSame('admin', $household->roleOf($admin));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/RestructurePolicyTest.php tests/Feature/HouseholdPolicyRolesTest.php`
Expected: FAIL — `manageMembers`/`transferOwnership`/`delete`/`roleOf` don't exist yet; `restructure` still returns true for a plain member.

- [ ] **Step 3: Add `Household::roleOf()`**

In `src/Models/Household.php`, add after `users()`:

```php
    /**
     * The given user's role in this household, or null if they aren't a member.
     * The one place every policy method and resource reads role from — no other
     * code should query `inventory_household_user.role` directly.
     */
    public function roleOf(User $user): ?string
    {
        /** @var HouseholdUserPivot|null $pivot */
        $pivot = $this->users()->wherePivot('user_id', $user->getKey())->first()?->pivot;

        return $pivot?->role;
    }
```

Also update `users()` to expose the new column:

```php
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'inventory_household_user',
            'household_id',
            'user_id',
        )->using(HouseholdUserPivot::class)->withPivot('joined_at', 'role');
    }
```

- [ ] **Step 4: Mirror `withPivot` on `User::households()`**

In `src/Models/User.php`, add `'role'` to the existing `withPivot('joined_at')` call so it reads `withPivot('joined_at', 'role')`.

- [ ] **Step 5: Add `role` to `HouseholdUserPivot`**

In `src/Models/HouseholdUserPivot.php`:

```php
<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string|null $joined_at
 * @property string $role
 */
class HouseholdUserPivot extends Pivot
{
    protected $table = 'inventory_household_user';
}
```

- [ ] **Step 6: Rewrite `HouseholdPolicy`**

Replace `src/Policies/HouseholdPolicy.php` entirely:

```php
<?php

namespace Spdotdev\Inventory\Policies;

use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

/**
 * `restructure` is the seam every mutating storage-structure route authorizes
 * against; that never changes when roles land — only this method's body did.
 * See `docs/superpowers/specs/2026-07-17-household-roles-design.md` for the
 * full role model (single transferable Owner; Admin manages structure and
 * membership; Member uses the household but can't reshape it).
 *
 * Note the 403-vs-404 posture throughout: `household.member` already 404s
 * non-members before any policy runs, so a 403 from here can only ever mean
 * "you are a member, but not one who may do this."
 */
class HouseholdPolicy
{
    /** Owner or Admin: rename/reorder/delete locations & shelves, edit theme. */
    public function restructure(User $user, Household $household): bool
    {
        return in_array($household->roleOf($user), ['owner', 'admin'], true);
    }

    /** Owner or Admin: promote/demote/remove members (never the Owner's own row). */
    public function manageMembers(User $user, Household $household): bool
    {
        return $this->restructure($user, $household);
    }

    /** Owner only: the sole path that changes who holds the Owner role. */
    public function transferOwnership(User $user, Household $household): bool
    {
        return $household->roleOf($user) === 'owner';
    }

    /** Owner only: deleting the household itself (not yet wired to a route). */
    public function delete(User $user, Household $household): bool
    {
        return $household->roleOf($user) === 'owner';
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/RestructurePolicyTest.php tests/Feature/HouseholdPolicyRolesTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 8: Run the full gate**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all PASS. (`HouseholdThemeTest` and every other existing test attach members with no explicit `role`, so they get the column default `'member'` — check whether any of those tests call `update()` or another `restructure`-gated route as a plain member and now fail; if so this is expected per spec and Task 4 will need `role: 'owner'` added to that test's setup. Note any such failures here for Task 4 to pick up.)

- [ ] **Step 9: Commit**

```bash
git add src/Policies/HouseholdPolicy.php src/Models/Household.php src/Models/User.php \
        src/Models/HouseholdUserPivot.php tests/Feature/RestructurePolicyTest.php \
        tests/Feature/HouseholdPolicyRolesTest.php
git commit -m "feat: HouseholdPolicy gains role-aware restructure + 3 new methods

restructure() now checks owner/admin instead of granting any member. Adds
manageMembers (same tier), transferOwnership and delete (owner only).
Household::roleOf(User) is the one place every policy method and resource
reads role from."
```

---

## Task 3: Backend — fix existing tests broken by role-gated `restructure`

**Files:**
- Modify: any existing Feature test whose setup attaches a member with no role and then calls a `restructure`-gated route (found in Task 2 Step 8). Likely candidates based on `restructure`'s call sites: `tests/Feature/HouseholdThemeTest.php`, `tests/Feature/LocationTest.php` (or similarly named), `tests/Feature/ShelfTest.php`, `tests/Feature/RestoreTest.php` — the actual list comes from Task 2's failing-gate output, not assumed here.

**Interfaces:**
- Consumes: `Household::roleOf`, the new `HouseholdPolicy` (Task 2).

- [ ] **Step 1: Run the full suite and list every new failure**

Run: `vendor/bin/phpunit 2>&1 | grep -B2 "FAILED\|Failed asserting"`
Expected: a list of tests that were passing before Task 2 and now 403 because their setup attaches a member with the default `'member'` role while calling a `restructure`-gated endpoint (theme update, location/shelf CRUD, reorder, restore).

- [ ] **Step 2: Fix each one by attaching as `'admin'` instead of the bare default**

For each failing test's member-setup helper (e.g. `HouseholdThemeTest::memberSetup()`), change:

```php
$household->users()->attach($user->getKey(), ['joined_at' => now()]);
```

to:

```php
$household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'admin']);
```

Use `'admin'`, not `'owner'`, unless the specific test is exercising owner-only behavior — these tests are about the underlying feature (theme, CRUD, restore), not about roles, and Admin is the minimal role that unblocks them. Do not touch tests that were already passing and stay passing (e.g. `leave`, `index`, `store`, `join`, `search`, stock actions `add`/`remove`/`move`, `export` — none of those are gated by `restructure`).

- [ ] **Step 3: Run the full suite again**

Run: `vendor/bin/phpunit`
Expected: PASS, same total test count as before Task 2 (minus the intentional `RestructurePolicyTest` rewrite) plus Task 1/2's new tests.

- [ ] **Step 4: Run the full gate**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature
git commit -m "test: attach test members as admin where restructure is exercised

restructure() is now role-gated (owner/admin only). Tests whose setup wasn't
about roles — theme, location/shelf CRUD, restore — attach their member as
admin so they keep testing the feature they were written for."
```

---

## Task 4: Backend — member management endpoints (`GET`/`PATCH`/`DELETE` members, `transfer-ownership`)

**Files:**
- Create: `src/Http/Controllers/Api/MemberController.php`, `src/Http/Requests/UpdateMemberRoleRequest.php`, `src/Http/Requests/TransferOwnershipRequest.php`, `src/Http/Resources/HouseholdMemberResource.php`
- Modify: `routes/api.php`, `src/Http/Controllers/Api/HouseholdController.php` (the `leave` method's sole-owner rule)
- Test: `tests/Feature/HouseholdMembersTest.php`

**Interfaces:**
- Consumes: `HouseholdPolicy::manageMembers/transferOwnership` (Task 2), `Household::roleOf` (Task 2).
- Produces: routes `GET/PATCH/DELETE households/{household}/members[/{user}]`, `POST households/{household}/transfer-ownership`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/HouseholdMembersTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdMembersTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function memberWithRole(Household $household, string $role, string $name = 'U'): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => $name, 'email' => "u{$n}@example.test", 'password' => 'secret-password']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return $user;
    }

    public function test_any_member_can_list_the_roster(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'AAAA-1111']);
        $owner = $this->memberWithRole($household, 'owner', 'Owner');
        $this->memberWithRole($household, 'member', 'Plain');
        Sanctum::actingAs($owner);

        $this->getJson("{$this->base}/households/{$household->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_an_admin_can_promote_a_member_to_admin(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'BBBB-2222']);
        $admin = $this->memberWithRole($household, 'admin');
        $member = $this->memberWithRole($household, 'member');
        Sanctum::actingAs($admin);

        $this->patchJson("{$this->base}/households/{$household->id}/members/{$member->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_a_member_cannot_change_anyones_role(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'CCCC-3333']);
        $member = $this->memberWithRole($household, 'member');
        $otherMember = $this->memberWithRole($household, 'member');
        Sanctum::actingAs($member);

        $this->patchJson("{$this->base}/households/{$household->id}/members/{$otherMember->id}", ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_setting_role_to_owner_via_patch_is_rejected(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'DDDD-4444']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($owner);

        $this->patchJson("{$this->base}/households/{$household->id}/members/{$admin->id}", ['role' => 'owner'])
            ->assertStatus(422);
    }

    public function test_the_owners_own_row_cannot_be_patched(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'EEEE-5555']);
        $owner = $this->memberWithRole($household, 'owner');
        Sanctum::actingAs($owner);

        $this->patchJson("{$this->base}/households/{$household->id}/members/{$owner->id}", ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_an_admin_can_remove_a_member(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'FFFF-6666']);
        $admin = $this->memberWithRole($household, 'admin');
        $member = $this->memberWithRole($household, 'member');
        Sanctum::actingAs($admin);

        $this->deleteJson("{$this->base}/households/{$household->id}/members/{$member->id}")
            ->assertOk();

        $this->assertNull($household->fresh()->roleOf($member));
    }

    public function test_removing_the_owner_is_forbidden(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'GGGG-7777']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("{$this->base}/households/{$household->id}/members/{$owner->id}")
            ->assertForbidden();
    }

    public function test_removing_a_non_member_is_404(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'HHHH-8888']);
        $admin = $this->memberWithRole($household, 'admin');
        $stranger = User::create(['name' => 'S', 'email' => 's@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($admin);

        $this->deleteJson("{$this->base}/households/{$household->id}/members/{$stranger->id}")
            ->assertNotFound();
    }

    public function test_the_owner_can_transfer_ownership(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'IIII-9999']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($owner);

        $this->postJson("{$this->base}/households/{$household->id}/transfer-ownership", ['user_id' => $admin->id])
            ->assertOk();

        $this->assertSame('owner', $household->fresh()->roleOf($admin));
        $this->assertSame('admin', $household->fresh()->roleOf($owner));
    }

    public function test_an_admin_cannot_transfer_ownership(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'JJJJ-0000']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($admin);

        $this->postJson("{$this->base}/households/{$household->id}/transfer-ownership", ['user_id' => $owner->id])
            ->assertForbidden();
    }

    public function test_the_owner_cannot_leave_without_transferring_first(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'KKKK-1111']);
        $owner = $this->memberWithRole($household, 'owner');
        $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($owner);

        $this->deleteJson("{$this->base}/households/{$household->id}/leave")
            ->assertStatus(409);
    }

    public function test_a_non_owner_can_still_leave_freely(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'LLLL-2222']);
        $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("{$this->base}/households/{$household->id}/leave")
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/HouseholdMembersTest.php`
Expected: FAIL — routes don't exist (404s across the board).

- [ ] **Step 3: Add the Form Requests**

Create `src/Http/Requests/UpdateMemberRoleRequest.php`:

```php
<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH households/{household}/members/{user}. `role` deliberately excludes
 * "owner" — becoming Owner only happens via POST .../transfer-ownership, so
 * there is exactly one code path that mints a new Owner.
 */
class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'member'])],
        ];
    }
}
```

Create `src/Http/Requests/TransferOwnershipRequest.php`:

```php
<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
        ];
    }
}
```

- [ ] **Step 4: Add `HouseholdMemberResource`**

Create `src/Http/Resources/HouseholdMemberResource.php`:

```php
<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\User;

/**
 * @mixin User
 */
class HouseholdMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \Spdotdev\Inventory\Models\HouseholdUserPivot $pivot */
        $pivot = $this->pivot;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $pivot->role,
            'joined_at' => $pivot->joined_at,
        ];
    }
}
```

- [ ] **Step 5: Write `MemberController`**

Create `src/Http/Controllers/Api/MemberController.php`:

```php
<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

        return (new HouseholdMemberResource($household->users()->whereKey($user->getKey())->first()))->response();
    }

    public function destroy(Household $household, User $user): JsonResponse
    {
        Gate::authorize('manageMembers', $household);

        $targetRole = $household->roleOf($user);
        abort_if($targetRole === null, 404);
        abort_if($targetRole === 'owner', 403, 'The owner cannot be removed.');

        $household->users()->detach($user->getKey());

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

        DB::transaction(function () use ($household, $newOwner, $currentOwner) {
            $household->users()->updateExistingPivot($newOwner->getKey(), ['role' => 'owner']);
            $household->users()->updateExistingPivot($currentOwner->getKey(), ['role' => 'admin']);
        });

        return response()->json(['message' => 'Ownership transferred.']);
    }
}
```

- [ ] **Step 6: Add the sole-owner leave rule to `HouseholdController::leave`**

In `src/Http/Controllers/Api/HouseholdController.php`, replace the `leave` method:

```php
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
        }

        return response()->json(['message' => 'Left the household.']);
    }
```

- [ ] **Step 7: Wire the routes**

In `routes/api.php`, add the `MemberController` import and, inside the existing `household.member` + `scopeBindings` group (right after the `leave` route), add:

```php
                Route::get('households/{household}/members', [MemberController::class, 'index'])->name('inventory.api.members.index');
                Route::patch('households/{household}/members/{user}', [MemberController::class, 'update'])->name('inventory.api.members.update');
                Route::delete('households/{household}/members/{user}', [MemberController::class, 'destroy'])->name('inventory.api.members.destroy');
                Route::post('households/{household}/transfer-ownership', [MemberController::class, 'transferOwnership'])->name('inventory.api.households.transfer-ownership');
```

`{user}` here binds a `User` model directly (not household-scoped, since a member's `User` row isn't a child of `Household` the way a location/shelf is) — Laravel's implicit route-model binding on the `User $user` parameter handles this with no extra config, matching how `HouseholdController` methods already type-hint `Household $household` from `{household}`.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/HouseholdMembersTest.php`
Expected: PASS, 12 tests.

- [ ] **Step 9: Run the full gate**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all PASS.

- [ ] **Step 10: Commit**

```bash
git add src/Http/Controllers/Api/MemberController.php src/Http/Controllers/Api/HouseholdController.php \
        src/Http/Requests/UpdateMemberRoleRequest.php src/Http/Requests/TransferOwnershipRequest.php \
        src/Http/Resources/HouseholdMemberResource.php routes/api.php tests/Feature/HouseholdMembersTest.php
git commit -m "feat: member management + transfer-ownership endpoints

GET/PATCH/DELETE members and POST transfer-ownership, all gated by
manageMembers/transferOwnership. The owner's own row is untouchable except
via transfer; leave() 409s for the sole owner instead of orphaning the
household."
```

---

## Task 5: Backend — `HouseholdResource` exposes role + capability booleans

**Files:**
- Modify: `src/Http/Resources/HouseholdResource.php`
- Test: `tests/Feature/HouseholdResourceRoleTest.php`

**Interfaces:**
- Consumes: `Household::roleOf`, `HouseholdPolicy` (Task 2).
- Produces: `HouseholdResource` JSON gains `role`, `can_restructure`, `can_manage_members`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HouseholdResourceRoleTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdResourceRoleTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    public function test_the_list_endpoint_reports_the_callers_own_role_and_capabilities(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'AAAA-1111']);
        $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => 'secret-password']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);
        Sanctum::actingAs($member);

        $this->getJson("{$this->base}/households")
            ->assertOk()
            ->assertJsonPath('data.0.role', 'member')
            ->assertJsonPath('data.0.can_restructure', false)
            ->assertJsonPath('data.0.can_manage_members', false);
    }

    public function test_an_admin_sees_true_capabilities(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'BBBB-2222']);
        $admin = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'secret-password']);
        $household->users()->attach($admin->getKey(), ['joined_at' => now(), 'role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson("{$this->base}/households")
            ->assertOk()
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.0.can_restructure', true)
            ->assertJsonPath('data.0.can_manage_members', true);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/HouseholdResourceRoleTest.php`
Expected: FAIL — `role`/`can_restructure`/`can_manage_members` keys missing from the JSON.

- [ ] **Step 3: Update `HouseholdResource`**

Replace `src/Http/Resources/HouseholdResource.php`:

```php
<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

/**
 * @mixin Household
 */
class HouseholdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $role = $user instanceof User ? $this->resource->roleOf($user) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            // All members may see the join code and invite others — that's
            // unrelated to the restructure/manage-members role gate.
            'join_code' => $this->join_code,
            // Phase-2 theme keys (null = client derives a default from the id).
            'color' => $this->color,
            'icon' => $this->icon,
            // The CALLER's own role + derived capabilities, so neither client
            // re-implements the role→capability mapping (roles design spec).
            'role' => $role,
            'can_restructure' => $user instanceof User && Gate::forUser($user)->allows('restructure', $this->resource),
            'can_manage_members' => $user instanceof User && Gate::forUser($user)->allows('manageMembers', $this->resource),
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/HouseholdResourceRoleTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Run the full gate**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Http/Resources/HouseholdResource.php tests/Feature/HouseholdResourceRoleTest.php
git commit -m "feat: HouseholdResource exposes the caller's role + capabilities

role/can_restructure/can_manage_members let both clients gate UI off one
server-computed source of truth instead of re-deriving role logic."
```

---

## Task 6: Web UI — role badges + member management on the household page

**Files:**
- Modify: `src/Http/Controllers/Web/WebHouseholdController.php`, `routes/web.php`
- Modify: the household `show` Blade view (find via `find resources/views -iname "*household*"`)
- Test: `tests/Feature/WebHouseholdMembersTest.php`

**Interfaces:**
- Consumes: `MemberController` logic pattern (Task 4) reused via the same `HouseholdPolicy` Gate calls; `Household::roleOf`.

- [ ] **Step 1: Locate the current show view and members rendering**

Run: `grep -rn "members" resources/views/ | grep -v vendor`

Read the matched view file before editing — it already renders `$members` (a plain `User` collection with the `pivot` relation loaded, from `WebHouseholdController::show()`'s `'members' => $household->users()->get()`).

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/WebHouseholdMembersTest.php` (adjust the route names/paths to match this repo's actual `routes/web.php` prefix, discovered in Step 1's view lookup — the web routes live under `Route::domain(...)->middleware('web')`, session-guarded, per `WebHouseholdController`'s existing `authorizeMember` pattern):

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class WebHouseholdMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_promote_a_member_via_the_web_route(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'AAAA-1111']);
        $admin = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => bcrypt('secret-password')]);
        $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => bcrypt('secret-password')]);
        $household->users()->attach($admin->getKey(), ['joined_at' => now(), 'role' => 'admin']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($admin, 'inventory')
            ->put("/households/{$household->id}/members/{$member->id}", ['role' => 'admin'])
            ->assertRedirect();

        $this->assertSame('admin', $household->fresh()->roleOf($member));
    }

    public function test_a_member_cannot_promote_anyone(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'BBBB-2222']);
        $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => bcrypt('secret-password')]);
        $other = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => bcrypt('secret-password')]);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);
        $household->users()->attach($other->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($member, 'inventory')
            ->put("/households/{$household->id}/members/{$other->id}", ['role' => 'admin'])
            ->assertForbidden();
    }
}
```

Note the guard name `'inventory'` is copied from this repo's existing web auth tests (`inventory` session guard on `inventory_users`, per `inventory-laravel/CLAUDE.md`) — confirm the exact guard name against an existing passing web test (e.g. `grep -rn "actingAs.*inventory" tests/Feature/`) before running this.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/WebHouseholdMembersTest.php`
Expected: FAIL — route `households/{household}/members/{user}` (PUT) doesn't exist.

- [ ] **Step 4: Add the web controller methods**

In `src/Http/Controllers/Web/WebHouseholdController.php`, add (following the file's existing `authorizeMember` + validation + redirect-back pattern seen in `update`/`leave`):

```php
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

        $currentOwner = $request->user();

        \Illuminate\Support\Facades\DB::transaction(function () use ($household, $newOwner, $currentOwner) {
            $household->users()->updateExistingPivot($newOwner->getKey(), ['role' => 'owner']);
            $household->users()->updateExistingPivot($currentOwner->getKey(), ['role' => 'admin']);
        });

        return back()->with('status', 'Ownership transferred.');
    }
```

Add the needed `use` statements at the top of the file: `use Illuminate\Http\RedirectResponse;`, `use Illuminate\Support\Facades\Gate;`, `use Illuminate\Validation\Rule;`, `use Spdotdev\Inventory\Models\User;` (skip any already present).

- [ ] **Step 5: Wire the web routes**

In `routes/web.php`, inside the same `household.member` + `scopeBindings` group as `/households/{household}/search`, add:

```php
                Route::put('/households/{household}/members/{user}', [WebHouseholdController::class, 'updateMemberRole'])->name('inventory.web.members.update');
                Route::delete('/households/{household}/members/{user}', [WebHouseholdController::class, 'removeMember'])->name('inventory.web.members.remove');
                Route::post('/households/{household}/transfer-ownership', [WebHouseholdController::class, 'transferOwnership'])->name('inventory.web.households.transfer-ownership');
```

- [ ] **Step 6: Update the Blade view**

In the household `show` view found in Step 1, extend the `@foreach ($members as $member)` (or equivalent) loop to show `$member->pivot->role` as a badge, and — wrapped in `@can('manageMembers', $household)` — a promote/demote form and a remove-member form per row, both hidden on the row where `$member->pivot->role === 'owner'`. Add a `@can('transferOwnership', $household)` block in the existing danger zone with a member-picker select + submit to the `transfer-ownership` route. Follow the existing Blade form conventions in this file (CSRF token, method-spoofing `@method('PUT')`/`@method('DELETE')`) — copy the exact markup pattern already used by the `leave` button found in Step 1.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/WebHouseholdMembersTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 8: Run the full gate**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Http/Controllers/Web/WebHouseholdController.php routes/web.php resources/views \
        tests/Feature/WebHouseholdMembersTest.php
git commit -m "feat: web UI for role badges + member management

Promote/demote/remove gated by manageMembers, transfer-ownership gated by
transferOwnership — same HouseholdPolicy the API uses, no duplicated
authorization logic."
```

---

## Task 7: Android — data layer (`MemberRepository`, DTOs, `HouseholdDto` role fields)

**Files:**
- Modify: `data/dto/HouseholdDtos.kt`
- Create: `data/dto/MemberDtos.kt`, `data/api/MemberApi.kt`, `data/member/MemberRepository.kt`, `data/member/MemberRepositoryImpl.kt`
- Modify: `di/NetworkModule.kt`, `di/RepositoryModule.kt`, `app/src/androidTest/java/dev/scuttle/inventory/di/TestNetworkModule.kt`, `data/auth/SessionCleaner.kt`
- Test: `app/src/test/java/dev/scuttle/inventory/MemberSerializationTest.kt`

**Interfaces:**
- Produces: `HouseholdDto.role: String`, `.can_restructure: Boolean`, `.can_manage_members: Boolean`; `MemberDto(id, name, role, joinedAt)`; `MemberRepository.list/updateRole/remove/transferOwnership`.

- [ ] **Step 1: Write the failing test**

Create `app/src/test/java/dev/scuttle/inventory/MemberSerializationTest.kt`:

```kotlin
package dev.scuttle.inventory

import dev.scuttle.inventory.data.dto.HouseholdDto
import kotlinx.serialization.json.Json
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class MemberSerializationTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test
    fun household_dto_decodes_role_and_capabilities() {
        val decoded =
            json.decodeFromString(
                HouseholdDto.serializer(),
                """{"id":1,"name":"Home","join_code":"AAAA-1111","role":"admin","can_restructure":true,"can_manage_members":true}""",
            )

        assertTrue(decoded.can_restructure)
        assertTrue(decoded.can_manage_members)
    }

    @Test
    fun a_member_role_decodes_false_capabilities() {
        val decoded =
            json.decodeFromString(
                HouseholdDto.serializer(),
                """{"id":1,"name":"Home","join_code":"AAAA-1111","role":"member","can_restructure":false,"can_manage_members":false}""",
            )

        assertFalse(decoded.can_restructure)
        assertFalse(decoded.can_manage_members)
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./gradlew testDebugUnitTest --tests '*MemberSerializationTest*'`
Expected: FAIL — `role`/`can_restructure`/`can_manage_members` unresolved on `HouseholdDto`, or missing-field decode error (no defaults yet).

- [ ] **Step 3: Extend `HouseholdDto`**

In `data/dto/HouseholdDtos.kt`, add three fields to `HouseholdDto` (no defaults — these must always be sent by the server, per the spec's "no client-side defaults on these two booleans" rule; an old cached value must never silently read as `true`):

```kotlin
@Serializable
data class HouseholdDto(
    val id: Long,
    val name: String,
    val join_code: String,
    val color: String? = null,
    val icon: String? = null,
    val role: String,
    val can_restructure: Boolean,
    val can_manage_members: Boolean,
)
```

- [ ] **Step 4: Create `MemberDtos.kt`**

Create `data/dto/MemberDtos.kt`:

```kotlin
package dev.scuttle.inventory.data.dto

import kotlinx.serialization.Serializable

@Serializable
data class MemberDto(
    val id: Long,
    val name: String,
    val role: String,
    val joined_at: String?,
)

@Serializable
data class MemberListResponse(
    val data: List<MemberDto>,
)

@Serializable
data class MemberResponse(
    val data: MemberDto,
)

@Serializable
data class UpdateMemberRoleRequest(
    val role: String,
)

@Serializable
data class TransferOwnershipRequest(
    val user_id: Long,
)
```

- [ ] **Step 5: Create `MemberApi`**

Create `data/api/MemberApi.kt`:

```kotlin
package dev.scuttle.inventory.data.api

import dev.scuttle.inventory.data.dto.MemberListResponse
import dev.scuttle.inventory.data.dto.MemberResponse
import dev.scuttle.inventory.data.dto.TransferOwnershipRequest
import dev.scuttle.inventory.data.dto.UpdateMemberRoleRequest
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Path

interface MemberApi {
    @GET("households/{household}/members")
    suspend fun list(
        @Path("household") householdId: Long,
    ): MemberListResponse

    @PATCH("households/{household}/members/{user}")
    suspend fun updateRole(
        @Path("household") householdId: Long,
        @Path("user") userId: Long,
        @Body body: UpdateMemberRoleRequest,
    ): MemberResponse

    @DELETE("households/{household}/members/{user}")
    suspend fun remove(
        @Path("household") householdId: Long,
        @Path("user") userId: Long,
    )

    @POST("households/{household}/transfer-ownership")
    suspend fun transferOwnership(
        @Path("household") householdId: Long,
        @Body body: TransferOwnershipRequest,
    )
}
```

- [ ] **Step 6: Create `MemberRepository` + Impl**

Create `data/member/MemberRepository.kt`:

```kotlin
package dev.scuttle.inventory.data.member

import dev.scuttle.inventory.data.dto.MemberDto

interface MemberRepository {
    suspend fun list(householdId: Long): List<MemberDto>

    suspend fun updateRole(
        householdId: Long,
        userId: Long,
        role: String,
    ): MemberDto

    suspend fun remove(
        householdId: Long,
        userId: Long,
    )

    suspend fun transferOwnership(
        householdId: Long,
        userId: Long,
    )

    /** Drop any in-memory cache so one account's data never bleeds into the next session. */
    fun clear() {}
}
```

Create `data/member/MemberRepositoryImpl.kt`:

```kotlin
package dev.scuttle.inventory.data.member

import dev.scuttle.inventory.data.api.MemberApi
import dev.scuttle.inventory.data.dto.MemberDto
import dev.scuttle.inventory.data.dto.TransferOwnershipRequest
import dev.scuttle.inventory.data.dto.UpdateMemberRoleRequest
import javax.inject.Inject

class MemberRepositoryImpl
    @Inject
    constructor(
        private val api: MemberApi,
    ) : MemberRepository {
        override suspend fun list(householdId: Long): List<MemberDto> = api.list(householdId).data

        override suspend fun updateRole(
            householdId: Long,
            userId: Long,
            role: String,
        ): MemberDto = api.updateRole(householdId, userId, UpdateMemberRoleRequest(role)).data

        override suspend fun remove(
            householdId: Long,
            userId: Long,
        ) = api.remove(householdId, userId)

        override suspend fun transferOwnership(
            householdId: Long,
            userId: Long,
        ) = api.transferOwnership(householdId, TransferOwnershipRequest(userId))
    }
```

(No cache: this repository has nothing that needs `SessionCleaner` wiring — Step 8 below only registers the DI bindings.)

- [ ] **Step 7: Register `MemberApi` in both network modules**

In `di/NetworkModule.kt`, add next to `provideHouseholdApi`:

```kotlin
    @Provides
    @Singleton
    fun provideMemberApi(retrofit: Retrofit): MemberApi = retrofit.create(MemberApi::class.java)
```

Add the same `@Provides` block to `app/src/androidTest/java/dev/scuttle/inventory/di/TestNetworkModule.kt` (miss this and every flow test fails to inject with an unhelpful Hilt error).

- [ ] **Step 8: Bind `MemberRepository`**

In `di/RepositoryModule.kt`, add next to `bindHouseholdRepository`:

```kotlin
    @Binds
    @Singleton
    abstract fun bindMemberRepository(impl: MemberRepositoryImpl): MemberRepository
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `./gradlew testDebugUnitTest --tests '*MemberSerializationTest*'`
Expected: PASS, 2 tests.

- [ ] **Step 10: Run the full gate**

Run: `./gradlew testDebugUnitTest ktlintCheck detekt`
Expected: all PASS. (Existing tests/fakes that construct `HouseholdDto` directly will now fail to compile — that's expected; Task 8 fixes every call site.)

- [ ] **Step 11: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/data app/src/main/java/dev/scuttle/inventory/di \
        app/src/androidTest/java/dev/scuttle/inventory/di app/src/test/java/dev/scuttle/inventory/MemberSerializationTest.kt
git commit -m "feat: member data layer (MemberRepository, DTOs, HouseholdDto role fields)

HouseholdDto gains role/can_restructure/can_manage_members with NO defaults —
the server must always send them, so an old cached value can never silently
read as true. MemberRepository is additive; existing fakes get fixed next."
```

---

## Task 8: Android — fix every `HouseholdDto` construction site broken by the new required fields

**Files:**
- Modify: every test fake and production call site that constructs `HouseholdDto(...)` positionally or with named args missing `role`/`can_restructure`/`can_manage_members` — found via the grep below, not enumerated here since the exact list depends on the current codebase state.

**Interfaces:**
- Consumes: `HouseholdDto` (Task 7).

- [ ] **Step 1: Find every construction site**

Run: `grep -rln "HouseholdDto(" app/src/main app/src/test app/src/androidTest`

- [ ] **Step 2: Fix each one**

For a production call site (e.g. a fake in a ViewModel test), add the three new fields. Use realistic values matching what the test is exercising — e.g. an admin-owned test fixture:

```kotlin
HouseholdDto(
    id = 1L,
    name = "Home",
    join_code = "AAAA-1111",
    role = "admin",
    can_restructure = true,
    can_manage_members = true,
)
```

For a member-tier fixture where the test is specifically about a Member's restricted view, use `role = "member", can_restructure = false, can_manage_members = false` instead — match the value to what that specific test asserts about, don't default every site to the same role.

- [ ] **Step 3: Run the full unit test suite**

Run: `./gradlew testDebugUnitTest`
Expected: PASS, all tests compile and pass.

- [ ] **Step 4: Run the full gate**

Run: `./gradlew testDebugUnitTest ktlintCheck detekt`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add -u app/src/main app/src/test app/src/androidTest
git commit -m "fix: add role/can_restructure/can_manage_members to every HouseholdDto fixture"
```

---

## Task 9: Android — gate edit-mode pencils on `can_restructure`

**Files:**
- Modify: `ui/households/HouseholdsScreen.kt`, `ui/location/LocationDetailScreen.kt` (or wherever the storage-overview/shelves edit-mode pencil composables live — confirm exact file via `grep -rln "editMode" app/src/main/java/dev/scuttle/inventory/ui`), `ui/households/HouseholdEditScreen.kt`
- Test: extend the relevant existing screen/ViewModel tests (e.g. `HouseholdsViewModelTest.kt`, `ShelvesViewModelTest.kt`, `StorageOverviewViewModelTest.kt` — confirm exact names via `grep -rln "editMode" app/src/test`)

**Interfaces:**
- Consumes: `HouseholdDto.can_restructure` (Task 7/8).

- [ ] **Step 1: Find every edit-mode pencil entry point**

Run: `grep -rln "editMode\|enterEditMode" app/src/main/java/dev/scuttle/inventory/ui`

- [ ] **Step 2: Write a failing test per screen**

For each screen found, add a test asserting the pencil/edit-mode-entry affordance does not render (or `enterEditMode()` is not offered) when the active household's `can_restructure` is `false`. Concretely, for the households list screen, add to its ViewModel or screen test:

```kotlin
    @Test
    fun a_member_household_does_not_show_the_edit_pencil() =
        runTest {
            val repo =
                FakeHouseholdRepository().apply {
                    households =
                        listOf(
                            HouseholdDto(
                                id = 1L,
                                name = "Home",
                                join_code = "AAAA-1111",
                                role = "member",
                                can_restructure = false,
                                can_manage_members = false,
                            ),
                        )
                }
            val viewModel = HouseholdsViewModel(repo, fakeHierarchyStore())

            assertFalse(viewModel.state.value.households.first().can_restructure)
        }
```

(Adjust to whatever fake/helper names already exist in the target test file — read it first. The assertion itself — that the DTO's `can_restructure` flows through unmodified to UI state — is the testable contract; the composable-level "pencil doesn't render" is covered by the existing instrumented flow-test pattern for that screen, extended the same way.)

- [ ] **Step 3: Run the tests to verify they fail (or pass trivially if the composable already ignores the flag)**

Run: `./gradlew testDebugUnitTest`
Expected: the DTO plumbing test passes immediately (Task 7 already added the field) — the actual gate is in the composable, which Step 4 adds.

- [ ] **Step 4: Gate the composables**

In each screen found in Step 1, wrap the pencil/edit-mode-entry `IconButton` (or equivalent) in a check against the active household's `can_restructure`. For example, in `HouseholdsScreen.kt` wherever the edit pencil for a given household row is rendered:

```kotlin
if (household.can_restructure) {
    IconButton(onClick = { viewModel.enterEditMode() }) {
        Icon(Icons.Default.Edit, contentDescription = stringResource(R.string.household_edit_cd))
    }
}
```

Apply the same pattern to the locations/shelves edit-mode pencil (gated on the active household's `can_restructure`, threaded down from whichever screen holds the current `HouseholdDto` — `StorageOverviewScreen`/`LocationDetailScreen` per the file found in Step 1) and to the household theme-edit entry point in `HouseholdEditScreen.kt`.

- [ ] **Step 5: Run the full gate**

Run: `./gradlew testDebugUnitTest ktlintCheck detekt`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/ui app/src/test
git commit -m "feat: gate edit-mode pencils on HouseholdDto.can_restructure

A Member no longer sees rename/reorder/delete/theme-edit affordances that
are guaranteed to 403 server-side. This mirrors, not replaces, the server
enforcement added in the backend tasks."
```

---

## Task 10: Android — Members screen + ViewModel

**Files:**
- Create: `ui/members/MembersViewModel.kt`, `ui/members/MembersScreen.kt`
- Modify: `ui/households/HouseholdEditScreen.kt` (entry point), `MainActivity.kt` (or wherever `NavHost`/`Routes` are defined — confirm via `grep -rln "object Routes" app/src/main`), `res/values/strings.xml`, `res/values-nl/strings.xml`
- Test: `app/src/test/java/dev/scuttle/inventory/MembersViewModelTest.kt`

**Interfaces:**
- Consumes: `MemberRepository` (Task 7).
- Produces: `MembersUiState(loading, members: List<MemberDto>, viewerRole: String, error)`, `MembersViewModel.promote(userId)`, `.demote(userId)`, `.remove(userId)`, `.transferOwnership(userId)`.

- [ ] **Step 1: Write the failing tests**

Create `app/src/test/java/dev/scuttle/inventory/MembersViewModelTest.kt`:

```kotlin
package dev.scuttle.inventory

import dev.scuttle.inventory.data.dto.MemberDto
import dev.scuttle.inventory.data.member.MemberRepository
import dev.scuttle.inventory.ui.members.MembersViewModel
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class FakeMemberRepository : MemberRepository {
    var members = mutableListOf<MemberDto>()
    var lastRoleUpdate: Pair<Long, String>? = null
    var lastRemovedId: Long? = null
    var lastTransferTargetId: Long? = null

    override suspend fun list(householdId: Long): List<MemberDto> = members

    override suspend fun updateRole(
        householdId: Long,
        userId: Long,
        role: String,
    ): MemberDto {
        lastRoleUpdate = userId to role
        val index = members.indexOfFirst { it.id == userId }
        val updated = members[index].copy(role = role)
        members[index] = updated
        return updated
    }

    override suspend fun remove(
        householdId: Long,
        userId: Long,
    ) {
        lastRemovedId = userId
        members.removeIf { it.id == userId }
    }

    override suspend fun transferOwnership(
        householdId: Long,
        userId: Long,
    ) {
        lastTransferTargetId = userId
        val newOwnerIndex = members.indexOfFirst { it.id == userId }
        val oldOwnerIndex = members.indexOfFirst { it.role == "owner" }
        members[newOwnerIndex] = members[newOwnerIndex].copy(role = "owner")
        if (oldOwnerIndex != -1) members[oldOwnerIndex] = members[oldOwnerIndex].copy(role = "admin")
    }
}

class MembersViewModelTest {
    @Test
    fun loading_populates_the_member_list() =
        runTest {
            val repo = FakeMemberRepository().apply { members.add(MemberDto(1, "Stan", "owner", null)) }
            val viewModel = MembersViewModel(repo)
            viewModel.load(householdId = 1)

            assertEquals(listOf("Stan"), viewModel.state.value.members.map { it.name })
        }

    @Test
    fun promoting_a_member_sends_admin() =
        runTest {
            val repo =
                FakeMemberRepository().apply {
                    members.add(MemberDto(1, "Owner", "owner", null))
                    members.add(MemberDto(2, "Plain", "member", null))
                }
            val viewModel = MembersViewModel(repo)
            viewModel.load(householdId = 1)

            viewModel.promote(2L)

            assertEquals(2L to "admin", repo.lastRoleUpdate)
        }

    @Test
    fun removing_a_member_drops_them_from_state() =
        runTest {
            val repo =
                FakeMemberRepository().apply {
                    members.add(MemberDto(1, "Owner", "owner", null))
                    members.add(MemberDto(2, "Plain", "member", null))
                }
            val viewModel = MembersViewModel(repo)
            viewModel.load(householdId = 1)

            viewModel.remove(2L)

            assertEquals(listOf("Owner"), viewModel.state.value.members.map { it.name })
        }

    @Test
    fun transferring_ownership_swaps_roles_in_state() =
        runTest {
            val repo =
                FakeMemberRepository().apply {
                    members.add(MemberDto(1, "Owner", "owner", null))
                    members.add(MemberDto(2, "Plain", "admin", null))
                }
            val viewModel = MembersViewModel(repo)
            viewModel.load(householdId = 1)

            viewModel.transferOwnership(2L)

            assertEquals("owner", viewModel.state.value.members.first { it.id == 2L }.role)
            assertEquals("admin", viewModel.state.value.members.first { it.id == 1L }.role)
        }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./gradlew testDebugUnitTest --tests '*MembersViewModelTest*'`
Expected: FAIL — `MembersViewModel` doesn't exist.

- [ ] **Step 3: Write `MembersViewModel`**

Create `ui/members/MembersViewModel.kt`:

```kotlin
package dev.scuttle.inventory.ui.members

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import dev.scuttle.inventory.data.dto.MemberDto
import dev.scuttle.inventory.data.error.toUserMessage
import dev.scuttle.inventory.data.member.MemberRepository
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class MembersUiState(
    val loading: Boolean = false,
    val members: List<MemberDto> = emptyList(),
    val error: String? = null,
)

@HiltViewModel
class MembersViewModel
    @Inject
    constructor(
        private val repository: MemberRepository,
    ) : ViewModel() {
        private val _state = MutableStateFlow(MembersUiState())
        val state: StateFlow<MembersUiState> = _state.asStateFlow()

        private var householdId: Long? = null

        fun load(householdId: Long) {
            this.householdId = householdId
            launchLoading {
                _state.update { it.copy(members = repository.list(householdId)) }
            }
        }

        fun promote(userId: Long) = setRole(userId, "admin")

        fun demote(userId: Long) = setRole(userId, "member")

        private fun setRole(
            userId: Long,
            role: String,
        ) {
            val id = householdId ?: return
            launchLoading {
                val updated = repository.updateRole(id, userId, role)
                _state.update { state ->
                    state.copy(members = state.members.map { if (it.id == userId) updated else it })
                }
            }
        }

        fun remove(userId: Long) {
            val id = householdId ?: return
            launchLoading {
                repository.remove(id, userId)
                _state.update { state -> state.copy(members = state.members.filter { it.id != userId }) }
            }
        }

        fun transferOwnership(userId: Long) {
            val id = householdId ?: return
            launchLoading {
                repository.transferOwnership(id, userId)
                _state.update { it.copy(members = repository.list(id)) }
            }
        }

        private fun launchLoading(block: suspend () -> Unit) {
            viewModelScope.launch {
                _state.update { it.copy(loading = true, error = null) }
                try {
                    block()
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    _state.update { it.copy(error = e.toUserMessage("Something went wrong.")) }
                } finally {
                    _state.update { it.copy(loading = false) }
                }
            }
        }
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./gradlew testDebugUnitTest --tests '*MembersViewModelTest*'`
Expected: PASS, 4 tests.

- [ ] **Step 5: Write `MembersScreen`**

Create `ui/members/MembersScreen.kt` — follow this app's standard screen shape (`Scaffold` + `TopAppBar` with back navigation, a `LazyColumn` of rows, a loading spinner keyed off `state.loading`, an error `Snackbar` keyed off `state.error`, per the pattern in `HouseholdEditScreen.kt`). Each row shows the member's name, a role badge (`stringResource` per role — `R.string.role_owner`/`role_admin`/`role_member`), and, only when the viewer's own capability allows it and the row isn't the Owner, promote/demote buttons and a remove button with a confirmation `AlertDialog` (mirroring the `confirmLeave` pattern already in `HouseholdEditScreen.kt`). If the viewer is themself the Owner, a "Transfer ownership" button opens a member-picker `AlertDialog` listing the other members, confirming via `viewModel.transferOwnership(pickedId)`.

The viewer's own role/capabilities aren't in `MembersUiState` — pass them into `MembersScreen` as parameters from the caller (the household edit screen already has the active `HouseholdDto`, including `can_manage_members` and `role`), rather than duplicating that lookup inside this screen.

- [ ] **Step 6: Add the strings (EN + NL)**

`res/values/strings.xml`:

```xml
    <!-- Members -->
    <string name="members_title">Members</string>
    <string name="role_owner">Owner</string>
    <string name="role_admin">Admin</string>
    <string name="role_member">Member</string>
    <string name="members_promote">Make admin</string>
    <string name="members_demote">Make member</string>
    <string name="members_remove">Remove</string>
    <string name="members_remove_confirm_title">Remove %1$s?</string>
    <string name="members_remove_confirm_body">They\'ll lose access to this household immediately.</string>
    <string name="members_transfer_ownership">Transfer ownership</string>
    <string name="members_transfer_ownership_pick">Who should become the new owner?</string>
```

`res/values-nl/strings.xml` (same keys, same order):

```xml
    <!-- Members -->
    <string name="members_title">Leden</string>
    <string name="role_owner">Eigenaar</string>
    <string name="role_admin">Beheerder</string>
    <string name="role_member">Lid</string>
    <string name="members_promote">Maak beheerder</string>
    <string name="members_demote">Maak lid</string>
    <string name="members_remove">Verwijderen</string>
    <string name="members_remove_confirm_title">%1$s verwijderen?</string>
    <string name="members_remove_confirm_body">Ze verliezen direct toegang tot dit huishouden.</string>
    <string name="members_transfer_ownership">Eigenaarschap overdragen</string>
    <string name="members_transfer_ownership_pick">Wie moet de nieuwe eigenaar worden?</string>
```

- [ ] **Step 7: Wire the entry point + route**

In `HouseholdEditScreen.kt`, add a "Members" row/button (visible to every member, not gated — viewing the roster is allowed for all, per the backend spec) that navigates to the new Members route with the current `householdId`. Add the route to wherever `Routes`/`NavHost` are defined (found via the Step-0 grep) following the existing typed-route pattern (e.g. `Routes.MEMBERS`, taking a `householdId` argument the same way the existing location-detail route does).

- [ ] **Step 8: Run the full gate**

Run: `./gradlew testDebugUnitTest ktlintCheck detekt`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/ui/members app/src/main/java/dev/scuttle/inventory/ui/households \
        app/src/main/res/values/strings.xml app/src/main/res/values-nl/strings.xml \
        app/src/test/java/dev/scuttle/inventory/MembersViewModelTest.kt
git commit -m "feat: Members screen — promote/demote/remove/transfer-ownership

Reached from the household edit page. The Owner's own row shows only
Transfer ownership; every other row shows promote/demote/remove gated on
the viewer's can_manage_members."
```

---

## Task 11: Android — leave-household 409 handling

**Files:**
- Modify: `ui/households/HouseholdsViewModel.kt`, `ui/households/HouseholdEditScreen.kt`
- Test: extend `app/src/test/java/dev/scuttle/inventory/HouseholdsViewModelTest.kt` (confirm exact filename via `grep -rln "class HouseholdsViewModelTest" app/src/test`)

**Interfaces:**
- Consumes: `HouseholdRepository.leave` (existing), `Throwable.toUserMessage` (existing `data/error/ErrorMapping.kt`).

- [ ] **Step 1: Write the failing test**

Add to the existing `HouseholdsViewModelTest.kt` (extend its existing `FakeHouseholdRepository` to let a test throw on `leave`):

```kotlin
    @Test
    fun leaving_as_the_sole_owner_surfaces_a_friendly_409_message() =
        runTest {
            val repo =
                FakeHouseholdRepository().apply {
                    households = listOf(HouseholdDto(1, "Home", "AAAA-1111", role = "owner", can_restructure = true, can_manage_members = true))
                    leaveThrows = HttpException(Response.error<Unit>(409, "".toResponseBody(null)))
                }
            val viewModel = HouseholdsViewModel(repo, fakeHierarchyStore())

            viewModel.leave(1L)

            assertEquals(
                "You're the only owner — transfer ownership before leaving this household.",
                viewModel.state.value.error,
            )
        }
```

(Adjust to whatever `FakeHouseholdRepository`'s existing shape is — read the file first; add a `leaveThrows: Throwable?` field to it if one doesn't exist, following the same pattern used by other Fake repositories in this suite for injecting a thrown error, e.g. `FakeShelfRepository`'s `deleteWithStrategy` test setup style.)

- [ ] **Step 2: Run the test to verify it fails**

Run: `./gradlew testDebugUnitTest --tests '*HouseholdsViewModelTest*'`
Expected: FAIL — the 409 case isn't distinguished from the generic fallback message yet (or the fake doesn't support throwing).

- [ ] **Step 3: Add the 409-specific message to `ErrorMapping.kt`**

This message is specific to leaving a household as its sole owner, and 409 is otherwise unused elsewhere in this app's error mapping — check `data/error/ErrorMapping.kt`'s `when (code())` block; if no other call site relies on a generic 409 fallback, add a case:

```kotlin
                409 -> "You're the only owner — transfer ownership before leaving this household."
```

placed alongside the existing 401/403/404/422/429/5xx cases in `Throwable.toUserMessage`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./gradlew testDebugUnitTest --tests '*HouseholdsViewModelTest*'`
Expected: PASS.

- [ ] **Step 5: Run the full gate**

Run: `./gradlew testDebugUnitTest ktlintCheck detekt`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/data/error/ErrorMapping.kt \
        app/src/test/java/dev/scuttle/inventory/HouseholdsViewModelTest.kt
git commit -m "feat: friendly message when the sole owner tries to leave (409)

Reuses the existing error-mapping fallback pattern instead of a new dialog
type — the leave confirmation already renders state.error."
```

---

## Task 12: Full-repo verification (both repos)

**Files:** none — verification only.

- [ ] **Step 1: Backend full gate**

Run (from `inventory-laravel`): `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all PASS.

- [ ] **Step 2: Android full gate**

Run (from `inventory-android`): `./gradlew testDebugUnitTest ktlintCheck detekt`
Expected: all PASS.

- [ ] **Step 3: Update CLAUDE.md's "Roles/permissions: coming" note in both repos**

In `inventory-laravel/CLAUDE.md`, replace the "Roles/permissions: coming" bullet under "Hard rules — LOCKED" with a note that roles are shipped, pointing at the spec (`docs/superpowers/specs/2026-07-17-household-roles-design.md`) the same way other shipped features in that file are documented.

In `inventory-android/CLAUDE.md`, update the "Roles/permissions: coming" paragraph under "Scope guardrails" the same way — `restructure` no longer "always returns true," and `can_restructure`/`can_manage_members` are now real per-caller flags gating the edit-mode pencils.

- [ ] **Step 4: Commit the doc updates**

```bash
cd /home/dev/inventory/inventory-laravel && git add CLAUDE.md && git commit -m "docs: roles/permissions shipped, update CLAUDE.md status"
cd /home/dev/inventory/inventory-android && git add CLAUDE.md && git commit -m "docs: roles/permissions shipped, update CLAUDE.md status"
```
