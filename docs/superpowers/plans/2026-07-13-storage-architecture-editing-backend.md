# Storage Architecture Editing — Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the storage hierarchy (household → location → shelf) renameable, reorderable, and safely deletable — replacing today's silent cascading hard delete with a strategy the user chooses, backed by soft delete and batch undo.

**Architecture:** Additive migrations put `deleted_at` + `deletion_batch_id` on locations/shelves/products, `position` on locations, `is_system` on shelves, `is_starred` on products. Destructive endpoints take a required **strategy** and a **client-supplied batch id**, executed inside one transaction by a `HierarchyDeleter` support class. A single `HouseholdPolicy@restructure` gate — the package's first policy — fronts every new mutating route and returns `true` for all members today, so Spec 2 (roles) changes one method body.

**Tech Stack:** PHP 8.3, Laravel 13, package namespace `Spdotdev\Inventory\`, PHPUnit 11 + orchestra/testbench, Larastan level 5, Pint (laravel preset).

## Global Constraints

Copied verbatim from the codebase's conventions. Every task's requirements implicitly include this section.

- **Namespace `Spdotdev\Inventory\` → `src/`.** API controllers **extend nothing** — `$this->authorize()` is unavailable; use `Gate::authorize(...)`.
- **All tables prefixed `inventory_`**, hardcoded in the migration, the model's `$table`, and every `->constrained()`. There is no config key for it.
- **Tests are PHPUnit, not Pest.** `class XTest extends Spdotdev\Inventory\Tests\TestCase`, methods `public function test_snake_case(): void`, `use RefreshDatabase;`.
- **There are NO factories.** `database/` contains only `migrations/`. Seed with `Model::create()` / relation `->create()` chains and a `private function memberHousehold()` helper per test class. **Do not introduce factories.**
- **Tests use an absolute base URL** (host-based routing): `private string $base = 'http://inventory.test/api/v1';`
- **Tenancy posture is 404, never 403.** `household.member` middleware + `->scopeBindings()`. A policy denial yields 403, which is correct *only* because non-members are already 404'd upstream.
- **Route ordering:** literal segments (`reorder`, `restore`) MUST be registered **before** the corresponding `Route::apiResource(...)`, or they are captured as `{location}` / `{shelf}`. See the existing `households/join` precedent and the comment at `routes/api.php:75-76`.
- **Migrations:** anonymous class `return new class extends Migration`, filename `2026_07_13_0000NN_<verb>_<what>_to_inventory_<table>.php` (the date segment is a zero-padded sequence, not a real timestamp), `->after('col')` on adds, a why-comment inside `up()`, and a real `down()`.
- **Models:** explicit `protected $table`, `/** @var list<string> */` on `$fillable`, casts via `protected function casts(): array` with `/** @return array<string, string> */`, `@property` docblocks for every column, relation generics (`@return HasMany<Shelf, $this>`). PHPStan level 5 requires all of these.
- **Resources:** `@mixin <Model>` docblock is mandatory. Flat arrays. Backed enums unwrapped with `->value`.
- **Controller return-type contract:** `index` → `AnonymousResourceCollection`; `store` → `JsonResponse` at **201** via `(new R($x))->response()->setStatusCode(201)`; `show`/`update` → bare `Resource` (200); `destroy` → `response()->json(['message' => '<Thing> deleted.'])` (**200**, not 204, not `data`-wrapped).
- **Broadcasting trap:** the `BroadcastHouseholdChange` observer only fires on **Eloquent model events**. Query-builder writes (`Model::query()->update(...)`, `upsert()`) fire **nothing**. Every bulk operation in this plan MUST `HouseholdChanged::dispatch($householdId)` explicitly after the transaction commits.
- **Gates:** `make style` (Pint), `make stan` (PHPStan 5), `make test` (PHPUnit). CI runs the whole suite twice — once on SQLite, once on **real MySQL 8.0**. Migrations must execute on MySQL.

---

## Task 1: Soft deletes on the hierarchy

Replaces the locked "hard deletes only" posture. This task changes existing delete behaviour, so it also updates the tests that assert on it.

**Files:**
- Create: `database/migrations/2026_07_13_000000_add_soft_deletes_to_inventory_hierarchy.php`
- Modify: `src/Models/StorageLocation.php`, `src/Models/Shelf.php`, `src/Models/Product.php`, `src/Models/Household.php`
- Test: `tests/Feature/SoftDeleteTest.php` (create), `tests/Feature/ResourceCrudTest.php` (modify)

**Interfaces:**
- Consumes: nothing.
- Produces: `StorageLocation`, `Shelf`, `Product` all use `SoftDeletes` and have a fillable `deletion_batch_id` (`?string`). `StorageLocation::products(): HasManyThrough<Product, Shelf, $this>`. `Household::shelves()` excludes shelves whose location is soft-deleted.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SoftDeleteTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class SoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function memberHousehold(): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return $household;
    }

    public function test_deleting_a_location_soft_deletes_it(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $location->delete();

        // The row survives — this is the whole point of the change. A mis-tap
        // must be recoverable, and a support-grade restore must always exist.
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertNotNull(StorageLocation::withTrashed()->find($location->id));
    }

    public function test_a_soft_deleted_location_disappears_from_the_api(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $location->delete();

        $this->getJson("{$this->base}/households/{$h->id}/locations")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("{$this->base}/households/{$h->id}/locations/{$location->id}")->assertNotFound();
    }

    public function test_products_under_a_soft_deleted_location_are_unreachable(): void
    {
        // HasManyThrough does NOT apply the *intermediate* model's soft-delete
        // scope. Without an explicit whereNull on the join, Household::shelves()
        // still resolves a shelf inside a deleted location — so the product
        // routes (which scope-bind through it) would stay live on a fridge the
        // user believes they deleted.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $location->delete();

        $this->getJson("{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertNotFound();
    }

    public function test_batch_id_is_persisted_on_a_deleted_row(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $location->deletion_batch_id = '11111111-1111-4111-8111-111111111111';
        $location->save();
        $location->delete();

        $this->assertDatabaseHas('inventory_storage_locations', [
            'id' => $location->id,
            'deletion_batch_id' => '11111111-1111-4111-8111-111111111111',
        ]);
    }

    public function test_location_products_relation_reaches_through_shelves(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);
        $shelf->products()->create(['name' => 'Corn', 'quantity' => 1]);

        // Needed by the location delete strategies: "does this location hold anything?"
        $this->assertSame(2, $location->products()->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/SoftDeleteTest.php`
Expected: FAIL — `assertSoftDeleted` errors because the `deleted_at` column does not exist, and `Call to undefined method StorageLocation::products()`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_13_000000_add_soft_deletes_to_inventory_hierarchy.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'inventory_storage_locations',
        'inventory_shelves',
        'inventory_products',
    ];

    public function up(): void
    {
        // Reverses the package's original "hard deletes only" posture (2026-07-13
        // user decision). Deleting a location used to ON DELETE CASCADE its whole
        // subtree — every shelf and every product — with no confirmation and no
        // undo. deleted_at makes that survivable.
        //
        // deletion_batch_id groups every row killed by ONE user gesture: deleting
        // a shelf plus its twelve products is one batch, so Undo restores it as a
        // unit. The CLIENT mints the uuid, because deleting three shelves is three
        // requests and only the client knows they were one gesture.
        //
        // The ON DELETE CASCADE foreign keys stay: a soft delete is an UPDATE and
        // never triggers them, and they remain correct for the eventual hard purge.
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->softDeletes();
                $table->uuid('deletion_batch_id')->nullable()->after('deleted_at');
                $table->index('deletion_batch_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['deletion_batch_id']);
                $table->dropColumn(['deleted_at', 'deletion_batch_id']);
            });
        }
    }
};
```

- [ ] **Step 4: Add `SoftDeletes` to the three models**

In `src/Models/StorageLocation.php` — add to the imports, the class docblock, the trait list, and `$fillable`, and add the `products()` relation:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spdotdev\Inventory\Enums\StorageType;

/**
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property StorageType $type
 * @property Carbon|null $deleted_at
 * @property string|null $deletion_batch_id
 */
class StorageLocation extends Model
{
    use SoftDeletes;

    protected $table = 'inventory_storage_locations';

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'name',
        'type',
        'deletion_batch_id',
    ];

    // ... casts() and household() / shelves() unchanged ...

    /**
     * Products across all of this location's shelves. Backs the "does this
     * location still hold anything?" check the delete strategies depend on.
     *
     * @return HasManyThrough<Product, Shelf, $this>
     */
    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(
            Product::class,
            Shelf::class,
            'location_id', // FK on shelves
            'shelf_id',    // FK on products
            'id',
            'id',
        );
    }
}
```

Apply the same three edits (import + `use SoftDeletes;` + `@property Carbon|null $deleted_at` / `@property string|null $deletion_batch_id` + `'deletion_batch_id'` in `$fillable`) to `src/Models/Shelf.php` and `src/Models/Product.php`.

- [ ] **Step 5: Fix `Household::shelves()` to exclude soft-deleted locations**

In `src/Models/Household.php`, replace the body of `shelves()`:

```php
    /**
     * Shelves across all of the household's locations. Backs scoped binding for
     * the /households/{household}/shelves/{shelf}/... routes.
     *
     * HasManyThrough applies the FINAL model's global scopes but not the
     * INTERMEDIATE model's — so without the explicit whereNull, a shelf inside a
     * soft-deleted location stays reachable and its products keep resolving on a
     * fridge the user believes they deleted.
     *
     * @return HasManyThrough<Shelf, StorageLocation, $this>
     */
    public function shelves(): HasManyThrough
    {
        return $this->hasManyThrough(
            Shelf::class,
            StorageLocation::class,
            'household_id', // FK on storage_locations
            'location_id',  // FK on shelves
            'id',
            'id',
        )->whereNull('inventory_storage_locations.deleted_at');
    }
```

- [ ] **Step 6: Run the new test to verify it passes**

Run: `./vendor/bin/phpunit tests/Feature/SoftDeleteTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 7: Fix the existing tests this breaks**

`tests/Feature/ResourceCrudTest.php` asserts hard deletion. Run the suite to find every one:

Run: `make test`
Expected: FAIL — `assertDatabaseMissing('inventory_storage_locations', ['id' => $id])` now fails because the row survives.

In `tests/Feature/ResourceCrudTest.php::test_location_crud`, change the final assertion:

```php
        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$id}")->assertOk();
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $id]);
```

Apply the same `assertDatabaseMissing` → `assertSoftDeleted` change to every hierarchy delete assertion the run reports (expect hits in `ResourceCrudTest` and any cascade test). **Do not** change assertions about `inventory_households` or `inventory_users` — households are NOT soft-deleted by this plan, and `HouseholdController::leave()` still hard-deletes the household when the last member leaves (its FK cascade then hard-deletes the subtree, soft-deleted rows included — which is correct).

- [ ] **Step 8: Run the full gate**

Run: `make test && make stan && make style`
Expected: all PASS. PHPStan needs the `@property Carbon|null $deleted_at` docblocks added in Step 4 — if it complains about `deleted_at`, that docblock is missing on one of the three models.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_13_000000_add_soft_deletes_to_inventory_hierarchy.php src/Models tests/Feature/SoftDeleteTest.php tests/Feature/ResourceCrudTest.php
git commit -m "feat: soft-delete the storage hierarchy with batch grouping

Deleting a location used to ON DELETE CASCADE its shelves and every product
on them, with no confirmation and no undo. Adds deleted_at plus a
deletion_batch_id that groups every row killed by one user gesture, so a
delete can be restored as a unit.

Also fixes Household::shelves(): HasManyThrough does not apply the
intermediate model's soft-delete scope, so shelves inside a deleted location
stayed reachable."
```

---

## Task 2: The `restructure` policy seam

The package's first policy. It returns `true` for every member today — its only job is to exist, so Spec 2 (roles) changes one method body instead of a hundred call sites.

**Files:**
- Create: `src/Policies/HouseholdPolicy.php`
- Modify: `src/InventoryServiceProvider.php`
- Test: `tests/Feature/RestructurePolicyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Gate::authorize('restructure', $household)` — callable from any controller. Denial → 403. Registered via `Gate::policy(Household::class, HouseholdPolicy::class)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RestructurePolicyTest.php`:

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

    public function test_a_member_may_restructure(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        $this->assertTrue(Gate::forUser($user)->allows('restructure', $household));
    }

    public function test_a_non_member_may_not_restructure(): void
    {
        // In practice household.member 404s a non-member long before the policy
        // runs. The policy still denies them, so the rule holds if that
        // middleware is ever removed from a route by mistake.
        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Private', 'join_code' => 'ZZZZ-9999']);

        $this->assertFalse(Gate::forUser($outsider)->allows('restructure', $household));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/RestructurePolicyTest.php`
Expected: FAIL — no ability named `restructure` is registered, so `allows()` returns false for the member too.

- [ ] **Step 3: Write the policy**

Create `src/Policies/HouseholdPolicy.php`:

```php
<?php

namespace Spdotdev\Inventory\Policies;

use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

/**
 * The package's only policy, and deliberately a seam rather than a feature.
 *
 * Every mutating storage-structure route authorizes against `restructure`. Today
 * it grants any member — matching the long-standing "all members are equal" rule.
 * When roles (owner/admin/member) land, THIS METHOD BODY is the thing that
 * changes; no call site moves.
 *
 * Note the 403-vs-404 posture: household.member already 404s non-members before
 * a policy ever runs, so a 403 from here can only ever mean "you are a member,
 * but not one who may restructure" — which is precisely the semantics roles need.
 */
class HouseholdPolicy
{
    public function restructure(User $user, Household $household): bool
    {
        return $household->users()->whereKey($user->getKey())->exists();
    }
}
```

- [ ] **Step 4: Register the policy**

In `src/InventoryServiceProvider.php`, add the imports and register the policy inside `boot()` (next to the existing `registerBroadcasting()` call):

```php
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Policies\HouseholdPolicy;

// ... inside boot():
        Gate::policy(Household::class, HouseholdPolicy::class);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./vendor/bin/phpunit tests/Feature/RestructurePolicyTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Policies/HouseholdPolicy.php src/InventoryServiceProvider.php tests/Feature/RestructurePolicyTest.php
git commit -m "feat: add the restructure policy seam

The package's first policy. Grants any household member today, matching the
existing all-members-equal rule. Every structure-mutating route authorizes
against it, so when owner/admin/member roles land, one method body changes
instead of every call site."
```

---

## Task 3: Reorder locations and shelves

**Files:**
- Create: `database/migrations/2026_07_13_000001_add_position_to_inventory_storage_locations.php`
- Create: `src/Http/Requests/ReorderRequest.php`
- Modify: `src/Models/StorageLocation.php`, `src/Http/Controllers/Api/LocationController.php`, `src/Http/Controllers/Api/ShelfController.php`, `src/Http/Resources/LocationResource.php`, `routes/api.php`
- Test: `tests/Feature/ReorderTest.php`

**Interfaces:**
- Consumes: `Gate::authorize('restructure', $household)` from Task 2.
- Produces: `PATCH /households/{h}/locations/reorder` and `PATCH /households/{h}/locations/{l}/shelves/reorder`, both taking `{"ids": [3, 1, 2]}` and returning the reordered collection. `LocationResource` now emits `position`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReorderTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class ReorderTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function memberHousehold(): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return $household;
    }

    public function test_locations_can_be_reordered(): void
    {
        $h = $this->memberHousehold();
        $a = $h->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $h->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);
        $c = $h->locations()->create(['name' => 'Ccc', 'type' => StorageType::Freezer]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$c->id, $a->id, $b->id],
        ])->assertOk();

        // index() must now honour position, not name — a manual drag is the
        // user's stated order and nothing may silently re-alphabetise it.
        $this->getJson("{$this->base}/households/{$h->id}/locations")
            ->assertOk()
            ->assertJsonPath('data.0.id', $c->id)
            ->assertJsonPath('data.1.id', $a->id)
            ->assertJsonPath('data.2.id', $b->id);
    }

    public function test_shelves_can_be_reordered(): void
    {
        $h = $this->memberHousehold();
        $loc = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $mid = $loc->shelves()->create(['name' => 'Middle', 'position' => 1]);
        $bot = $loc->shelves()->create(['name' => 'Bottom', 'position' => 2]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$loc->id}/shelves/reorder", [
            'ids' => [$bot->id, $top->id, $mid->id],
        ])->assertOk();

        $this->getJson("{$this->base}/households/{$h->id}/locations/{$loc->id}/shelves")
            ->assertOk()
            ->assertJsonPath('data.0.id', $bot->id)
            ->assertJsonPath('data.1.id', $top->id)
            ->assertJsonPath('data.2.id', $mid->id);
    }

    public function test_reorder_broadcasts_to_the_household(): void
    {
        // The BroadcastHouseholdChange observer only fires on Eloquent model
        // events. A reorder is a query-builder write, which fires NOTHING — so
        // the controller must dispatch explicitly or a second member's list
        // silently goes stale.
        Event::fake([HouseholdChanged::class]);
        $h = $this->memberHousehold();
        $a = $h->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $h->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);

        Event::fake([HouseholdChanged::class]); // reset: the creates above already pinged

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$b->id, $a->id],
        ])->assertOk();

        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $h->id,
        );
    }

    public function test_reorder_rejects_an_id_from_another_household(): void
    {
        $h = $this->memberHousehold();
        $mine = $h->locations()->create(['name' => 'Mine', 'type' => StorageType::Fridge]);

        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $theirs = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$theirs->id, $mine->id],
        ])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_reorder_is_all_or_nothing(): void
    {
        // A partial write would leave a half-sorted list, which is worse than
        // no write at all — the user cannot tell which half took.
        $h = $this->memberHousehold();
        $a = $h->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $h->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$a->id, 99999],
        ])->assertStatus(422);

        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $a->id, 'position' => 0]);
        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $b->id, 'position' => 0]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/ReorderTest.php`
Expected: FAIL — 404, the `reorder` routes do not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_13_000001_add_position_to_inventory_storage_locations.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            // Locations were ordered by name; shelves already had `position`.
            // Drag-to-reorder needs the same column here. Existing rows all land
            // at 0, so index() falls back to the name tie-break and nothing
            // visibly moves until a user actually drags something.
            $table->unsignedInteger('position')->default(0)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
```

- [ ] **Step 4: Update the `StorageLocation` model**

Add `position` to `@property`, `$fillable`, `$attributes`, and `casts()` in `src/Models/StorageLocation.php`:

```php
/**
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property StorageType $type
 * @property int $position
 * @property Carbon|null $deleted_at
 * @property string|null $deletion_batch_id
 */
class StorageLocation extends Model
{
    use SoftDeletes;

    protected $table = 'inventory_storage_locations';

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'name',
        'type',
        'position',
        'deletion_batch_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StorageType::class,
            'position' => 'integer',
        ];
    }
```

- [ ] **Step 5: Write the shared reorder request**

Create `src/Http/Requests/ReorderRequest.php`:

```php
<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A whole-list reorder: the client sends the ids in their new order and the
 * server rewrites every position in one transaction.
 *
 * One bulk call rather than N individual PATCHes, because a partial failure
 * mid-drag would leave a half-sorted list the user cannot reason about.
 */
class ReorderRequest extends FormRequest
{
    /** Guards against a pathological payload; no real household has this many. */
    public const MAX_IDS = 500;

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
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        /** @var array{ids: list<int>} $data */
        $data = $this->validated();

        return array_map('intval', $data['ids']);
    }
}
```

- [ ] **Step 6: Add the reorder action to `LocationController`**

In `src/Http/Controllers/Api/LocationController.php`: change `index()` to sort by position, and add `reorder()`.

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Http\Requests\ReorderRequest;

    public function index(Household $household): AnonymousResourceCollection
    {
        // Manual order wins; name is only the tie-break for locations that have
        // never been dragged (they all sit at position 0).
        return LocationResource::collection(
            $household->locations()->orderBy('position')->orderBy('name')->get(),
        );
    }

    /**
     * Rewrite every location's position from the ids the client sends, in one
     * transaction — a half-applied drag is worse than a rejected one.
     */
    public function reorder(ReorderRequest $request, Household $household): AnonymousResourceCollection
    {
        Gate::authorize('restructure', $household);

        $ids = $request->ids();
        $owned = $household->locations()->whereKey($ids)->pluck('id')->all();

        // Every id must be a live location of THIS household. Anything else —
        // another household's id, a deleted id, a typo — rejects the whole call.
        if (count($owned) !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['The list must contain every location in this household, and only those.'],
            ]);
        }

        DB::transaction(function () use ($ids, $household) {
            foreach ($ids as $position => $id) {
                $household->locations()->whereKey($id)->update(['position' => $position]);
            }
        });

        // Query-builder updates fire no Eloquent events, so the observer never
        // sees this. Ping explicitly or other members' lists go stale.
        HouseholdChanged::dispatch((int) $household->getKey());

        return $this->index($household);
    }
```

- [ ] **Step 7: Add the reorder action to `ShelfController`**

In `src/Http/Controllers/Api/ShelfController.php`, add the same imports and:

```php
    /**
     * Rewrite every shelf's position within this location. See
     * LocationController::reorder — same contract, same all-or-nothing rule.
     */
    public function reorder(ReorderRequest $request, Household $household, StorageLocation $location): AnonymousResourceCollection
    {
        Gate::authorize('restructure', $household);

        $ids = $request->ids();
        $owned = $location->shelves()->whereKey($ids)->pluck('id')->all();

        if (count($owned) !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['The list must contain every shelf in this location, and only those.'],
            ]);
        }

        DB::transaction(function () use ($ids, $location) {
            foreach ($ids as $position => $id) {
                $location->shelves()->whereKey($id)->update(['position' => $position]);
            }
        });

        HouseholdChanged::dispatch((int) $household->getKey());

        return $this->index($household, $location);
    }
```

- [ ] **Step 8: Expose `position` on `LocationResource`**

In `src/Http/Resources/LocationResource.php`:

```php
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'position' => $this->position,
        ];
    }
```

- [ ] **Step 9: Register the routes BEFORE the apiResource**

In `routes/api.php`, inside the `household.member` + `scopeBindings()` group, add these two lines **immediately above** `Route::apiResource('households.locations', ...)`:

```php
                // Literal segments must precede the apiResource, or `reorder` is
                // captured as {location} / {shelf}. Same rule as households/join.
                Route::patch('households/{household}/locations/reorder', [LocationController::class, 'reorder'])->name('inventory.api.locations.reorder');
                Route::patch('households/{household}/locations/{location}/shelves/reorder', [ShelfController::class, 'reorder'])->name('inventory.api.shelves.reorder');

                Route::apiResource('households.locations', LocationController::class)->shallow(false);
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Feature/ReorderTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 11: Run the full gate**

Run: `make test && make stan && make style`
Expected: all PASS.

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_07_13_000001_add_position_to_inventory_storage_locations.php src/Http/Requests/ReorderRequest.php src/Http/Controllers/Api/LocationController.php src/Http/Controllers/Api/ShelfController.php src/Http/Resources/LocationResource.php src/Models/StorageLocation.php routes/api.php tests/Feature/ReorderTest.php
git commit -m "feat: reorder locations and shelves

Adds position to locations (shelves already had it) and a bulk reorder
endpoint at each level, taking the full id list and rewriting positions in
one transaction — a partial write would leave a half-sorted list.

Reorder is a query-builder write, which fires no Eloquent events, so
HouseholdChanged is dispatched explicitly; otherwise other members' lists
silently go stale."
```

---

## Task 4: The Unsorted system shelf

**Files:**
- Create: `database/migrations/2026_07_13_000002_add_is_system_to_inventory_hierarchy.php`
- Modify: `src/Models/Shelf.php`, `src/Models/StorageLocation.php`, `src/Http/Controllers/Api/ShelfController.php`, `src/Http/Resources/ShelfResource.php`
- Test: `tests/Feature/UnsortedShelfTest.php`

**Interfaces:**
- Consumes: soft deletes (Task 1).
- Produces: `Shelf::$is_system` (bool). `StorageLocation::unsortedShelf(): Shelf` — finds or lazily creates the location's Unsorted shelf. `ShelfResource` emits `is_system`. System shelves cannot be renamed, always sort last, and cannot be deleted while occupied.

**Note on naming/i18n:** the server stores the literal name `Unsorted`. The client localises the label when `is_system` is true, so no server-side translation is needed.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/UnsortedShelfTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class UnsortedShelfTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    /** @return array{Household, StorageLocation} */
    private function memberLocation(): array
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        return [$household, $location];
    }

    public function test_unsorted_shelf_is_created_lazily_and_reused(): void
    {
        [, $location] = $this->memberLocation();

        $first = $location->unsortedShelf();
        $second = $location->unsortedShelf();

        $this->assertTrue($first->is_system);
        $this->assertSame('Unsorted', $first->name);
        $this->assertSame($first->id, $second->id, 'unsortedShelf() must find, not duplicate');
        $this->assertSame(1, $location->shelves()->where('is_system', true)->count());
    }

    public function test_unsorted_shelf_sorts_last(): void
    {
        [$h, $location] = $this->memberLocation();
        $unsorted = $location->unsortedShelf();
        $top = $location->shelves()->create(['name' => 'Top', 'position' => 0]);

        // is_system sorts after position, so Unsorted stays at the bottom no
        // matter what positions the real shelves hold.
        $this->getJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves")
            ->assertOk()
            ->assertJsonPath('data.0.id', $top->id)
            ->assertJsonPath('data.1.id', $unsorted->id)
            ->assertJsonPath('data.1.is_system', true);
    }

    public function test_the_unsorted_shelf_cannot_be_renamed(): void
    {
        [$h, $location] = $this->memberLocation();
        $unsorted = $location->unsortedShelf();

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$unsorted->id}", [
            'name' => 'Hijacked',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('inventory_shelves', ['id' => $unsorted->id, 'name' => 'Unsorted']);
    }

    public function test_the_unsorted_shelf_cannot_be_deleted_while_occupied(): void
    {
        [$h, $location] = $this->memberLocation();
        $unsorted = $location->unsortedShelf();
        $unsorted->products()->create(['name' => 'Orphan peas', 'quantity' => 1]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$unsorted->id}")
            ->assertStatus(422);

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $unsorted->id]);
    }

    public function test_an_empty_unsorted_shelf_can_be_deleted(): void
    {
        [$h, $location] = $this->memberLocation();
        $unsorted = $location->unsortedShelf();

        // Nothing precious about it once empty — it is recreated on demand.
        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$unsorted->id}")
            ->assertOk();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $unsorted->id]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/UnsortedShelfTest.php`
Expected: FAIL — `Call to undefined method StorageLocation::unsortedShelf()`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_13_000002_add_is_system_to_inventory_hierarchy.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_shelves', function (Blueprint $table) {
            // Marks the "Unsorted" shelf — the holding area for products whose
            // shelf was deleted but which the user chose to keep. It is created
            // lazily (only when something is first unsorted into it), cannot be
            // renamed, always sorts last, and cannot be deleted while occupied.
            $table->boolean('is_system')->default(false)->after('position');
        });

        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            // Unused today. Added now so a future household-level holding area
            // (for products whose whole LOCATION was deleted) is a code change
            // rather than another migration against a live table.
            $table->boolean('is_system')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_shelves', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });

        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
```

- [ ] **Step 4: Update the models**

In `src/Models/Shelf.php` — add `@property bool $is_system`, add `'is_system'` to `$fillable`, add `'is_system' => false` to `$attributes`, and `'is_system' => 'boolean'` to `casts()`.

In `src/Models/StorageLocation.php` — add `@property bool $is_system`, `'is_system'` to `$fillable`, `'is_system' => false` to `$attributes`, `'is_system' => 'boolean'` to `casts()`, and add the lazy accessor:

```php
    /**
     * This location's Unsorted shelf, created on first use.
     *
     * Lazy on purpose: a household that never deletes a non-empty shelf never
     * sees an Unsorted shelf at all. Creating one up-front for every location
     * would put an empty system shelf in front of every user to serve a case
     * most of them never hit.
     */
    public function unsortedShelf(): Shelf
    {
        $existing = $this->shelves()->where('is_system', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->shelves()->create([
            'name' => 'Unsorted',
            'is_system' => true,
            'position' => 0, // irrelevant: is_system sorts it last regardless
        ]);
    }
```

- [ ] **Step 5: Enforce the system-shelf rules in `ShelfController`**

In `src/Http/Controllers/Api/ShelfController.php` — sort system shelves last in `index()`, and guard `update()` and `destroy()`:

```php
use Illuminate\Validation\ValidationException;

    public function index(Household $household, StorageLocation $location): AnonymousResourceCollection
    {
        // is_system first in the sort => Unsorted (is_system = true = 1) always
        // lands after the real shelves, whatever positions they hold.
        return ShelfResource::collection(
            $location->shelves()->orderBy('is_system')->orderBy('position')->get(),
        );
    }

    public function update(ShelfRequest $request, Household $household, StorageLocation $location, Shelf $shelf): ShelfResource
    {
        Gate::authorize('restructure', $household);

        $data = $request->validated();

        // The Unsorted shelf is a fixed concept the client localises off
        // is_system. Letting a user rename it to "Bananas" would leave the app
        // showing a translated label that matches nothing in the database.
        if ($shelf->is_system && array_key_exists('name', $data)) {
            throw ValidationException::withMessages([
                'name' => ['The Unsorted shelf cannot be renamed.'],
            ]);
        }

        $shelf->update($data);

        return new ShelfResource($shelf);
    }

    public function destroy(Household $household, StorageLocation $location, Shelf $shelf): JsonResponse
    {
        Gate::authorize('restructure', $household);

        // Deleting an occupied Unsorted shelf would strand the very products it
        // exists to protect. Empty, it is disposable — unsortedShelf() rebuilds
        // it on demand.
        if ($shelf->is_system && $shelf->products()->exists()) {
            throw ValidationException::withMessages([
                'shelf' => ['The Unsorted shelf still holds products. Move them first.'],
            ]);
        }

        $shelf->delete();

        return response()->json(['message' => 'Shelf deleted.']);
    }
```

> **Note:** `destroy()` gets its full strategy handling in Task 5; this step only adds the system-shelf guard and the policy call.

- [ ] **Step 6: Expose `is_system` and `product_count` on `ShelfResource`**

In `src/Http/Resources/ShelfResource.php`:

```php
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'location_id' => $this->location_id,
            'is_system' => $this->is_system,
            // The client needs this BEFORE it can ask the delete question: the
            // strategy dialog says "3 shelves · 17 products", and without a count
            // it cannot tell the user what is at stake.
            'product_count' => $this->products()->count(),
        ];
    }
```

> **N+1 warning:** `index()` renders many shelves, so this count runs per row. Eager-load it — change `ShelfController::index()` to `$location->shelves()->withCount('products')->orderBy('is_system')->orderBy('position')->get()` and read `$this->products_count` in the resource instead. Do both; the raw `->count()` above is only correct for the single-resource `show`/`store`/`update` paths.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Feature/UnsortedShelfTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_13_000002_add_is_system_to_inventory_hierarchy.php src/Models tests/Feature/UnsortedShelfTest.php src/Http/Controllers/Api/ShelfController.php src/Http/Resources/ShelfResource.php
git commit -m "feat: add the Unsorted system shelf

The holding area for products whose shelf was deleted but which the user
chose to keep. Created lazily on first use, sorts last, cannot be renamed,
and cannot be deleted while it still holds products.

Also adds an unused is_system to locations, so a future household-level
holding area does not need another migration against a live table."
```

---

## Task 5: Shelf delete strategies

**Files:**
- Create: `src/Enums/ShelfDeleteStrategy.php`, `src/Http/Requests/DeleteShelfRequest.php`, `src/Support/HierarchyDeleter.php`
- Modify: `src/Http/Controllers/Api/ShelfController.php`
- Test: `tests/Feature/ShelfDeleteStrategyTest.php`

**Interfaces:**
- Consumes: `unsortedShelf()` (Task 4), soft deletes (Task 1), `Gate::authorize('restructure', ...)` (Task 2).
- Produces: `HierarchyDeleter::deleteShelf(Shelf $shelf, string $batchId, ?ShelfDeleteStrategy $strategy, ?int $targetShelfId): void`. `DELETE .../shelves/{s}` accepts `{strategy, target_shelf_id?, deletion_batch_id}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ShelfDeleteStrategyTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class ShelfDeleteStrategyTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $batch = '11111111-1111-4111-8111-111111111111';

    /** @return array{Household, StorageLocation, Shelf, Product} */
    private function shelfWithProduct(): array
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        return [$household, $location, $shelf, $product];
    }

    private function url(Household $h, StorageLocation $l, Shelf $s): string
    {
        return "{$this->base}/households/{$h->id}/locations/{$l->id}/shelves/{$s->id}";
    }

    public function test_deleting_an_occupied_shelf_without_a_strategy_is_rejected(): void
    {
        // This is the bug the whole spec exists to fix: today this call silently
        // hard-deletes the product. The server must refuse to guess.
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), ['deletion_batch_id' => $this->batch])
            ->assertStatus(422)
            ->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $s->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $p->id]);
    }

    public function test_an_empty_shelf_needs_no_strategy(): void
    {
        [$h, $l, $s] = $this->shelfWithProduct();
        Product::query()->delete();

        $this->deleteJson($this->url($h, $l, $s), ['deletion_batch_id' => $this->batch])->assertOk();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id]);
    }

    public function test_move_products_reassigns_them_to_the_target_shelf(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();
        $target = $l->shelves()->create(['name' => 'Bottom', 'position' => 1]);

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'move_products',
            'target_shelf_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id]);
        $this->assertDatabaseHas('inventory_products', ['id' => $p->id, 'shelf_id' => $target->id, 'deleted_at' => null]);
    }

    public function test_unsort_products_moves_them_to_the_unsorted_shelf(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'unsort_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $unsorted = $l->shelves()->where('is_system', true)->firstOrFail();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id]);
        $this->assertDatabaseHas('inventory_products', ['id' => $p->id, 'shelf_id' => $unsorted->id, 'deleted_at' => null]);
    }

    public function test_delete_products_soft_deletes_them_in_the_same_batch(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        // Same batch id on both rows — that is what lets one Undo bring back the
        // shelf AND its products as a unit.
        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_products', ['id' => $p->id, 'deletion_batch_id' => $this->batch]);
    }

    public function test_move_to_a_shelf_in_another_household_is_rejected(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();
        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'X', 'type' => StorageType::Pantry])
            ->shelves()->create(['name' => 'Y', 'position' => 0]);

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'move_products',
            'target_shelf_id' => $foreign->id,
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('target_shelf_id');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $s->id]);
    }

    public function test_move_products_to_the_shelf_being_deleted_is_rejected(): void
    {
        [$h, $l, $s] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'move_products',
            'target_shelf_id' => $s->id,
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('target_shelf_id');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/ShelfDeleteStrategyTest.php`
Expected: FAIL — the delete succeeds with no strategy (returns 200, not 422).

- [ ] **Step 3: Write the strategy enum**

Create `src/Enums/ShelfDeleteStrategy.php`:

```php
<?php

namespace Spdotdev\Inventory\Enums;

/**
 * What to do with a shelf's products when the shelf is deleted. There is no
 * default: the server refuses to guess, because guessing wrong destroys data.
 */
enum ShelfDeleteStrategy: string
{
    /** Reassign the products to another shelf the user picks. */
    case MoveProducts = 'move_products';

    /** Reassign them to this location's Unsorted shelf — off-shelf, still in the fridge. */
    case UnsortProducts = 'unsort_products';

    /** Soft-delete them alongside the shelf, in the same batch. */
    case DeleteProducts = 'delete_products';
}
```

- [ ] **Step 4: Write the delete request**

Create `src/Http/Requests/DeleteShelfRequest.php`:

```php
<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spdotdev\Inventory\Enums\ShelfDeleteStrategy;
use Spdotdev\Inventory\Models\Shelf;

/**
 * Deleting a shelf that still holds products REQUIRES an explicit strategy.
 * The client always knows the product count, so a missing strategy is a client
 * bug, not a user choice — 422 it rather than silently destroying stock.
 */
class DeleteShelfRequest extends FormRequest
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
            'strategy' => [Rule::requiredIf(fn () => $this->shelfHasProducts()), Rule::enum(ShelfDeleteStrategy::class)],
            'target_shelf_id' => [
                Rule::requiredIf(fn () => $this->input('strategy') === ShelfDeleteStrategy::MoveProducts->value),
                'integer',
            ],
            // Client-minted: one user gesture may span several requests (deleting
            // three shelves), and only the client knows they were one gesture.
            'deletion_batch_id' => ['required', 'uuid'],
        ];
    }

    private function shelfHasProducts(): bool
    {
        $shelf = $this->route('shelf');

        return $shelf instanceof Shelf && $shelf->products()->exists();
    }

    public function strategy(): ?ShelfDeleteStrategy
    {
        $value = $this->input('strategy');

        return is_string($value) ? ShelfDeleteStrategy::from($value) : null;
    }

    public function batchId(): string
    {
        return (string) $this->input('deletion_batch_id');
    }

    public function targetShelfId(): ?int
    {
        $value = $this->input('target_shelf_id');

        return $value === null ? null : (int) $value;
    }
}
```

- [ ] **Step 5: Write the `HierarchyDeleter`**

Create `src/Support/HierarchyDeleter.php`:

```php
<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Enums\ShelfDeleteStrategy;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;

/**
 * Executes a structural delete as one transaction, stamping every row it kills
 * with the caller's batch id so the whole gesture can be restored as a unit.
 *
 * Everything here is a query-builder write — which fires NO Eloquent events, and
 * therefore never reaches the BroadcastHouseholdChange observer. That is
 * deliberate (one deterministic ping beats N model events), but it means this
 * class MUST dispatch HouseholdChanged itself. It does, once, after commit.
 */
class HierarchyDeleter
{
    /**
     * Delete a shelf, doing what the caller asked with its products.
     *
     * @throws ValidationException when the move target is invalid
     */
    public static function deleteShelf(
        Household $household,
        Shelf $shelf,
        string $batchId,
        ?ShelfDeleteStrategy $strategy,
        ?int $targetShelfId,
    ): void {
        $target = null;

        if ($strategy === ShelfDeleteStrategy::MoveProducts) {
            // Must be a live shelf of the SAME household, and not the shelf we
            // are about to delete (which would strand the products on a corpse).
            $target = $household->shelves()->whereKey($targetShelfId)->first();

            if ($target === null || (int) $target->getKey() === (int) $shelf->getKey()) {
                throw ValidationException::withMessages([
                    'target_shelf_id' => ['Pick a different shelf in this household.'],
                ]);
            }
        }

        DB::transaction(function () use ($shelf, $batchId, $strategy, $target) {
            $now = now();

            if ($strategy === ShelfDeleteStrategy::MoveProducts && $target !== null) {
                $shelf->products()->update(['shelf_id' => $target->getKey()]);
            }

            if ($strategy === ShelfDeleteStrategy::UnsortProducts) {
                $unsorted = $shelf->location->unsortedShelf();
                $shelf->products()->update(['shelf_id' => $unsorted->getKey()]);
            }

            if ($strategy === ShelfDeleteStrategy::DeleteProducts) {
                $shelf->products()->update([
                    'deleted_at' => $now,
                    'deletion_batch_id' => $batchId,
                ]);
            }

            $shelf->newQuery()->whereKey($shelf->getKey())->update([
                'deleted_at' => $now,
                'deletion_batch_id' => $batchId,
            ]);
        });

        HouseholdChanged::dispatch((int) $household->getKey());
    }
}
```

- [ ] **Step 6: Wire it into `ShelfController::destroy`**

Replace `destroy()` in `src/Http/Controllers/Api/ShelfController.php`:

```php
use Spdotdev\Inventory\Http\Requests\DeleteShelfRequest;
use Spdotdev\Inventory\Support\HierarchyDeleter;

    public function destroy(DeleteShelfRequest $request, Household $household, StorageLocation $location, Shelf $shelf): JsonResponse
    {
        Gate::authorize('restructure', $household);

        if ($shelf->is_system && $shelf->products()->exists()) {
            throw ValidationException::withMessages([
                'shelf' => ['The Unsorted shelf still holds products. Move them first.'],
            ]);
        }

        HierarchyDeleter::deleteShelf(
            $household,
            $shelf,
            $request->batchId(),
            $request->strategy(),
            $request->targetShelfId(),
        );

        return response()->json([
            'message' => 'Shelf deleted.',
            'deletion_batch_id' => $request->batchId(),
        ]);
    }
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Feature/ShelfDeleteStrategyTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 8: Run the full gate**

Run: `make test && make stan && make style`
Expected: all PASS. **`ResourceCrudTest` will now fail** on any shelf delete that sends no `deletion_batch_id` — add `['deletion_batch_id' => '11111111-1111-4111-8111-111111111111']` as the second argument to those `deleteJson` calls.

- [ ] **Step 9: Commit**

```bash
git add src/Enums/ShelfDeleteStrategy.php src/Http/Requests/DeleteShelfRequest.php src/Support/HierarchyDeleter.php src/Http/Controllers/Api/ShelfController.php tests/Feature/ShelfDeleteStrategyTest.php tests/Feature/ResourceCrudTest.php
git commit -m "feat: require a strategy to delete a shelf holding products

Deleting a shelf used to destroy its products silently. It now demands one of
move_products / unsort_products / delete_products, and 422s if a non-empty
shelf is deleted with no strategy — the server refuses to guess, because
guessing wrong destroys stock.

All rows killed by one gesture share the client-supplied batch id, so Undo
restores the shelf and its products as a unit."
```

---

## Task 6: Location delete strategies + shelf reparenting

**Files:**
- Create: `src/Enums/LocationDeleteStrategy.php`, `src/Http/Requests/DeleteLocationRequest.php`
- Modify: `src/Support/HierarchyDeleter.php`, `src/Http/Controllers/Api/LocationController.php`, `src/Http/Requests/ShelfRequest.php`, `src/Http/Controllers/Api/ShelfController.php`
- Test: `tests/Feature/LocationDeleteStrategyTest.php`

**Interfaces:**
- Consumes: `HierarchyDeleter` (Task 5).
- Produces: `HierarchyDeleter::deleteLocation(Household $h, StorageLocation $l, string $batchId, ?LocationDeleteStrategy $strategy, ?int $targetLocationId): void`. `PATCH .../shelves/{s}` accepts a writable `location_id`.

**Why there is no `unsort` at this level:** "unsorted" means *off-shelf but still in this location*, and the location is the thing being deleted. The only coherent options are move the contents elsewhere, or delete them.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LocationDeleteStrategyTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class LocationDeleteStrategyTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $batch = '22222222-2222-4222-8222-222222222222';

    private function memberHousehold(): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return $household;
    }

    /** A location holding one shelf with one product on it. */
    private function stockedLocation(Household $h, string $name = 'Chest'): StorageLocation
    {
        $location = $h->locations()->create(['name' => $name, 'type' => StorageType::Freezer]);
        $location->shelves()->create(['name' => 'Top', 'position' => 0])
            ->products()->create(['name' => 'Peas', 'quantity' => 2]);

        return $location;
    }

    public function test_deleting_a_stocked_location_without_a_strategy_is_rejected(): void
    {
        $h = $this->memberHousehold();
        $location = $this->stockedLocation($h);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_move_contents_reparents_the_shelves(): void
    {
        // This is why shelf reparenting exists at all: "move this fridge's
        // contents to the pantry" IS reparenting its shelves. The products come
        // along for free — they hang off the shelf, which never changed identity.
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h, 'Chest');
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);

        $shelf = $source->shelves()->firstOrFail();
        $product = $shelf->products()->firstOrFail();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $source->id]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $shelf->id, 'location_id' => $target->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'shelf_id' => $shelf->id, 'deleted_at' => null]);
    }

    public function test_delete_contents_soft_deletes_the_whole_subtree_in_one_batch(): void
    {
        $h = $this->memberHousehold();
        $location = $this->stockedLocation($h);
        $shelf = $location->shelves()->firstOrFail();
        $product = $shelf->products()->firstOrFail();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        // All three levels, one batch — so one Undo brings the whole fridge back.
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_products', ['id' => $product->id, 'deletion_batch_id' => $this->batch]);
    }

    public function test_an_empty_location_needs_no_strategy(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Empty', 'type' => StorageType::Other]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_move_contents_to_a_location_in_another_household_is_rejected(): void
    {
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h);
        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $foreign->id,
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('target_location_id');
    }

    public function test_a_shelf_can_be_reparented_to_another_location(): void
    {
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h, 'Chest');
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);
        $shelf = $source->shelves()->firstOrFail();

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$source->id}/shelves/{$shelf->id}", [
            'location_id' => $target->id,
        ])->assertOk()->assertJsonPath('data.location_id', $target->id);
    }

    public function test_a_shelf_cannot_be_reparented_into_another_household(): void
    {
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h);
        $shelf = $source->shelves()->firstOrFail();

        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$source->id}/shelves/{$shelf->id}", [
            'location_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/LocationDeleteStrategyTest.php`
Expected: FAIL — the location delete succeeds with no strategy.

- [ ] **Step 3: Write the strategy enum**

Create `src/Enums/LocationDeleteStrategy.php`:

```php
<?php

namespace Spdotdev\Inventory\Enums;

/**
 * What to do with a location's contents when the location is deleted.
 *
 * There is deliberately NO `unsort` here. "Unsorted" means off-shelf but still
 * IN this location — and the location is the thing being deleted. The only
 * coherent choices are: take the contents somewhere else, or destroy them.
 */
enum LocationDeleteStrategy: string
{
    /** Reparent the location's shelves (products ride along) into another location. */
    case MoveContents = 'move_contents';

    /** Soft-delete the shelves and their products alongside the location, in one batch. */
    case DeleteContents = 'delete_contents';
}
```

- [ ] **Step 4: Write the delete request**

Create `src/Http/Requests/DeleteLocationRequest.php`:

```php
<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spdotdev\Inventory\Enums\LocationDeleteStrategy;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Deleting a location that still holds shelves REQUIRES an explicit strategy.
 * See DeleteShelfRequest — same reasoning, one level up.
 */
class DeleteLocationRequest extends FormRequest
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
            'strategy' => [Rule::requiredIf(fn () => $this->locationHasContents()), Rule::enum(LocationDeleteStrategy::class)],
            'target_location_id' => [
                Rule::requiredIf(fn () => $this->input('strategy') === LocationDeleteStrategy::MoveContents->value),
                'integer',
            ],
            'deletion_batch_id' => ['required', 'uuid'],
        ];
    }

    private function locationHasContents(): bool
    {
        $location = $this->route('location');

        return $location instanceof StorageLocation && $location->shelves()->exists();
    }

    public function strategy(): ?LocationDeleteStrategy
    {
        $value = $this->input('strategy');

        return is_string($value) ? LocationDeleteStrategy::from($value) : null;
    }

    public function batchId(): string
    {
        return (string) $this->input('deletion_batch_id');
    }

    public function targetLocationId(): ?int
    {
        $value = $this->input('target_location_id');

        return $value === null ? null : (int) $value;
    }
}
```

- [ ] **Step 5: Add `deleteLocation` to `HierarchyDeleter`**

Append to `src/Support/HierarchyDeleter.php` (adding the `LocationDeleteStrategy` and `StorageLocation` imports):

```php
    /**
     * Delete a location, doing what the caller asked with its contents.
     *
     * @throws ValidationException when the move target is invalid
     */
    public static function deleteLocation(
        Household $household,
        StorageLocation $location,
        string $batchId,
        ?LocationDeleteStrategy $strategy,
        ?int $targetLocationId,
    ): void {
        $target = null;

        if ($strategy === LocationDeleteStrategy::MoveContents) {
            $target = $household->locations()->whereKey($targetLocationId)->first();

            if ($target === null || (int) $target->getKey() === (int) $location->getKey()) {
                throw ValidationException::withMessages([
                    'target_location_id' => ['Pick a different location in this household.'],
                ]);
            }
        }

        DB::transaction(function () use ($location, $batchId, $strategy, $target) {
            $now = now();

            if ($strategy === LocationDeleteStrategy::MoveContents && $target !== null) {
                // Reparent the shelves. Products hang off the shelf and never
                // change identity, so they come along without being touched.
                $location->shelves()->update(['location_id' => $target->getKey()]);
            }

            if ($strategy === LocationDeleteStrategy::DeleteContents) {
                $shelfIds = $location->shelves()->pluck('id')->all();

                Product::query()->whereIn('shelf_id', $shelfIds)->update([
                    'deleted_at' => $now,
                    'deletion_batch_id' => $batchId,
                ]);

                $location->shelves()->update([
                    'deleted_at' => $now,
                    'deletion_batch_id' => $batchId,
                ]);
            }

            $location->newQuery()->whereKey($location->getKey())->update([
                'deleted_at' => $now,
                'deletion_batch_id' => $batchId,
            ]);
        });

        HouseholdChanged::dispatch((int) $household->getKey());
    }
```

- [ ] **Step 6: Wire it into `LocationController::destroy`**

Replace `destroy()` in `src/Http/Controllers/Api/LocationController.php`:

```php
use Spdotdev\Inventory\Http\Requests\DeleteLocationRequest;
use Spdotdev\Inventory\Support\HierarchyDeleter;

    public function destroy(DeleteLocationRequest $request, Household $household, StorageLocation $location): JsonResponse
    {
        Gate::authorize('restructure', $household);

        HierarchyDeleter::deleteLocation(
            $household,
            $location,
            $request->batchId(),
            $request->strategy(),
            $request->targetLocationId(),
        );

        return response()->json([
            'message' => 'Location deleted.',
            'deletion_batch_id' => $request->batchId(),
        ]);
    }
```

Also add `Gate::authorize('restructure', $household);` as the first line of `LocationController::update()`.

- [ ] **Step 7: Make `location_id` writable on a shelf**

In `src/Http/Requests/ShelfRequest.php`:

```php
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:50'],
            'position' => ['sometimes', 'integer', 'min:0'],
            // Reparenting. No UI gesture exposes this yet, but the location
            // delete's move_contents strategy IS a reparent, and a future
            // drag-between-locations should be a client change, not a migration.
            // Household scoping is enforced in the controller — a Rule::exists
            // here cannot see the household.
            'location_id' => ['sometimes', 'integer'],
        ];
    }
```

- [ ] **Step 8: Enforce household scoping on the reparent**

In `src/Http/Controllers/Api/ShelfController.php::update()`, after the system-shelf guard:

```php
        // A Rule::exists in the request cannot see the household, so scope here:
        // without this a member could reparent a shelf into another household.
        if (array_key_exists('location_id', $data)) {
            $targetExists = $household->locations()->whereKey($data['location_id'])->exists();

            if (! $targetExists) {
                throw ValidationException::withMessages([
                    'location_id' => ['The selected location is not in this household.'],
                ]);
            }
        }

        $shelf->update($data);
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Feature/LocationDeleteStrategyTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 10: Run the full gate**

Run: `make test && make stan && make style`
Expected: all PASS. As in Task 5, existing location `deleteJson` calls in `ResourceCrudTest` need a `deletion_batch_id`.

- [ ] **Step 11: Commit**

```bash
git add src/Enums/LocationDeleteStrategy.php src/Http/Requests/DeleteLocationRequest.php src/Http/Requests/ShelfRequest.php src/Support/HierarchyDeleter.php src/Http/Controllers/Api tests/Feature/LocationDeleteStrategyTest.php tests/Feature/ResourceCrudTest.php
git commit -m "feat: require a strategy to delete a location holding shelves

move_contents reparents the location's shelves into another location (the
products ride along on the shelf); delete_contents soft-deletes the whole
subtree in one batch. There is no unsort option here: unsorted means
off-shelf but still in this location, and the location is what is being
deleted.

Makes location_id writable on a shelf, since move_contents IS a reparent.
Household scoping is enforced in the controller because a Rule::exists
cannot see the household."
```

---

## Task 7: Restore a deletion batch

**Files:**
- Create: `src/Http/Controllers/Api/RestoreController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/RestoreTest.php`

**Interfaces:**
- Consumes: `deletion_batch_id` stamped by Tasks 5–6.
- Produces: `POST /households/{h}/restore/{batch}` → 200 `{"message": "...", "restored": <int>}`, or 409 if the batch cannot be restored, or 404 if the batch is not this household's.

**Why the route lives at household level:** `->scopeBindings()` resolves `{shelf}` through `$location->shelves()`, which the `SoftDeletes` global scope filters — so a soft-deleted shelf 404s on **every** nested route. A restore endpoint keyed by shelf id could therefore never be reached. Keying by batch, at the household, sidesteps that entirely.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RestoreTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class RestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $batch = '33333333-3333-4333-8333-333333333333';

    private function memberHousehold(string $email = 'stan@example.test', string $code = 'AAAA-1111'): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => $email, 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => $code]);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return $household;
    }

    public function test_restoring_a_batch_brings_back_the_shelf_and_its_products(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk()
            ->assertJsonPath('restored', 2);

        // The whole gesture comes back as a unit — that is the point of the batch.
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_restoring_a_location_batch_brings_back_the_whole_subtree(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk()
            ->assertJsonPath('restored', 3);

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_restoring_an_unknown_batch_is_a_409(): void
    {
        $h = $this->memberHousehold();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertStatus(409);
    }

    public function test_a_batch_from_another_household_cannot_be_restored(): void
    {
        // Batch ids are client-minted, so a malicious client could guess one.
        // Restoring must be scoped to rows in the caller's own household.
        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);
        $foreign->deletion_batch_id = $this->batch;
        $foreign->save();
        $foreign->delete();

        $h = $this->memberHousehold();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertStatus(409);

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $foreign->id]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/RestoreTest.php`
Expected: FAIL — 404, the restore route does not exist.

- [ ] **Step 3: Write the controller**

Create `src/Http/Controllers/Api/RestoreController.php`:

```php
<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Undo one deletion gesture.
 *
 * Keyed by batch at the HOUSEHOLD level, not by resource id, because scoped
 * route-model binding resolves {shelf} through $location->shelves() — which the
 * SoftDeletes global scope filters. A soft-deleted shelf therefore 404s on every
 * nested route, so a restore keyed by shelf id could never be reached at all.
 */
class RestoreController
{
    public function __invoke(Household $household, string $batch): JsonResponse
    {
        Gate::authorize('restructure', $household);

        $locationIds = StorageLocation::withTrashed()
            ->where('household_id', $household->getKey())
            ->whereNotNull('deleted_at')
            ->where('deletion_batch_id', $batch)
            ->pluck('id');

        // Shelves and products carry no household_id, so scope them by walking
        // down from the household's own locations. A batch id is client-minted
        // and therefore guessable — never trust it alone.
        $householdLocationIds = StorageLocation::withTrashed()
            ->where('household_id', $household->getKey())
            ->pluck('id');

        $shelfIds = Shelf::withTrashed()
            ->whereIn('location_id', $householdLocationIds)
            ->whereNotNull('deleted_at')
            ->where('deletion_batch_id', $batch)
            ->pluck('id');

        $householdShelfIds = Shelf::withTrashed()
            ->whereIn('location_id', $householdLocationIds)
            ->pluck('id');

        $productIds = Product::withTrashed()
            ->whereIn('shelf_id', $householdShelfIds)
            ->whereNotNull('deleted_at')
            ->where('deletion_batch_id', $batch)
            ->pluck('id');

        $total = $locationIds->count() + $shelfIds->count() + $productIds->count();

        // Nothing to restore: an unknown batch, a batch belonging to someone
        // else, or one already purged by the retention job. 409 rather than 404
        // — the household is real, the undo just isn't possible any more.
        if ($total === 0) {
            return response()->json([
                'message' => 'Nothing to restore. This was already restored, or permanently removed.',
            ], 409);
        }

        DB::transaction(function () use ($locationIds, $shelfIds, $productIds) {
            // Parents first, so a restored shelf never lands under a still-deleted
            // location.
            StorageLocation::withTrashed()->whereKey($locationIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
            Shelf::withTrashed()->whereKey($shelfIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
            Product::withTrashed()->whereKey($productIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
        });

        HouseholdChanged::dispatch((int) $household->getKey());

        return response()->json([
            'message' => 'Restored.',
            'restored' => $total,
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, add the import and put this line inside the `household.member` group, above the `apiResource` block:

```php
use Spdotdev\Inventory\Http\Controllers\Api\RestoreController;

                Route::post('households/{household}/restore/{batch}', RestoreController::class)->name('inventory.api.restore');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Feature/RestoreTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/Api/RestoreController.php routes/api.php tests/Feature/RestoreTest.php
git commit -m "feat: restore a deletion batch

POST /households/{h}/restore/{batch} undoes one deletion gesture as a unit —
the shelf and every product that went with it.

Keyed by batch at the household level rather than by resource id: scoped
binding resolves {shelf} through the SoftDeletes global scope, so a
soft-deleted shelf 404s on every nested route and could never be restored via
its own id. Batch ids are client-minted and guessable, so the restore is
scoped to rows in the caller's own household."
```

---

## Task 8: Purge soft-deleted rows after the retention window

**Files:**
- Create: `src/Console/Commands/PruneDeletedCommand.php`
- Modify: `config/inventory.php`, `src/InventoryServiceProvider.php`
- Test: `tests/Feature/PruneDeletedTest.php`

**Interfaces:**
- Consumes: `deleted_at` (Task 1).
- Produces: `php artisan inventory:deleted:prune`, driven by `config('inventory.deleted_retention_days')` (default 30, env `INVENTORY_DELETED_RETENTION_DAYS`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PruneDeletedTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Tests\TestCase;

class PruneDeletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hard_deletes_rows_past_the_retention_window(): void
    {
        config()->set('inventory.deleted_retention_days', 30);

        $h = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $old = $h->locations()->create(['name' => 'Old', 'type' => StorageType::Freezer]);
        $recent = $h->locations()->create(['name' => 'Recent', 'type' => StorageType::Fridge]);

        $old->delete();
        $recent->delete();

        StorageLocation::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('inventory:deleted:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('inventory_storage_locations', ['id' => $old->id]);
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $recent->id]);
    }

    public function test_retention_of_zero_disables_pruning(): void
    {
        config()->set('inventory.deleted_retention_days', 0);

        $h = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $old = $h->locations()->create(['name' => 'Old', 'type' => StorageType::Freezer]);
        $old->delete();
        StorageLocation::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subYears(5)]);

        $this->artisan('inventory:deleted:prune')->assertExitCode(0);

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $old->id]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/PruneDeletedTest.php`
Expected: FAIL — command `inventory:deleted:prune` does not exist.

- [ ] **Step 3: Add the config key**

In `config/inventory.php`, alongside `client_errors_retention_days`:

```php
    /*
    |--------------------------------------------------------------------------
    | Soft-deleted row retention
    |--------------------------------------------------------------------------
    |
    | How long a soft-deleted location/shelf/product survives before it is hard
    | deleted for good. The in-app Undo is only a snackbar long; this window is
    | what makes a support-grade restore possible days later. 0 disables pruning.
    |
    */

    'deleted_retention_days' => (int) env('INVENTORY_DELETED_RETENTION_DAYS', 30),
```

- [ ] **Step 4: Write the command**

Create `src/Console/Commands/PruneDeletedCommand.php`:

```php
<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

class PruneDeletedCommand extends Command
{
    protected $signature = 'inventory:deleted:prune';

    protected $description = 'Hard delete soft-deleted locations, shelves and products past the retention window.';

    public function handle(): int
    {
        $days = (int) config('inventory.deleted_retention_days');

        if ($days <= 0) {
            $this->info('Deleted-row pruning is disabled (retention = 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        // Children first. A location's forceDelete would ON DELETE CASCADE its
        // subtree anyway, but a shelf soft-deleted on its own (parent still
        // alive) has no cascade to ride — so each level is pruned explicitly.
        $products = Product::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();
        $shelves = Shelf::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();
        $locations = StorageLocation::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();

        $this->info("Pruned {$locations} location(s), {$shelves} shelf/shelves, {$products} product(s) deleted more than {$days} day(s) ago.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Register the command**

In `src/InventoryServiceProvider.php`, add `PruneDeletedCommand::class` to the existing `$this->commands([...])` array (next to `PruneClientErrorsCommand::class`).

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Feature/PruneDeletedTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 7: Commit**

```bash
git add src/Console/Commands/PruneDeletedCommand.php src/InventoryServiceProvider.php config/inventory.php tests/Feature/PruneDeletedTest.php
git commit -m "feat: prune soft-deleted rows past the retention window

inventory:deleted:prune hard deletes locations/shelves/products soft-deleted
more than INVENTORY_DELETED_RETENTION_DAYS (default 30) ago. Children are
pruned first: a shelf deleted on its own has no live parent whose cascade it
could ride."
```

---

## Task 9: Star a product

**Files:**
- Create: `database/migrations/2026_07_13_000003_add_is_starred_to_inventory_products.php`
- Modify: `src/Models/Product.php`, `src/Http/Requests/ProductRequest.php`, `src/Http/Resources/ProductResource.php`
- Test: `tests/Feature/ProductStarTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Product::$is_starred` (bool), settable via the existing `PATCH .../products/{p}`, emitted by `ProductResource`.

**Why server-side, unlike location/shelf favourites:** a staple is a household fact ("we always keep milk"), not a personal one. Location and shelf favourites stay in device-local `SharedPrefs` — and because a star is only ever a **marker and a filter, never a sort** (spec D3), it does not matter that the two live in different places.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProductStarTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class ProductStarTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    public function test_a_product_can_be_starred_and_unstarred(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $h = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $h->users()->attach($user->getKey(), ['joined_at' => now()]);
        $shelf = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer])
            ->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        $url = "{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}";

        $this->getJson($url)->assertOk()->assertJsonPath('data.is_starred', false);

        $this->patchJson($url, ['is_starred' => true])->assertOk()->assertJsonPath('data.is_starred', true);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'is_starred' => true]);

        $this->patchJson($url, ['is_starred' => false])->assertOk()->assertJsonPath('data.is_starred', false);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/ProductStarTest.php`
Expected: FAIL — `data.is_starred` is missing from the response.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_13_000003_add_is_starred_to_inventory_products.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            // "Staples" — the things this household always keeps. Server-side
            // (unlike location/shelf favourites, which stay device-local) because
            // it is a household fact, not a personal one.
            //
            // A star is a MARKER and a FILTER, never a sort: manual drag order is
            // the user's stated order, and nothing may silently override it.
            $table->boolean('is_starred')->default(false)->after('is_mandatory');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->dropColumn('is_starred');
        });
    }
};
```

- [ ] **Step 4: Update the model, request and resource**

`src/Models/Product.php` — add `@property bool $is_starred`, `'is_starred'` to `$fillable`, and `'is_starred' => 'boolean'` to `casts()`.

`src/Http/Requests/ProductRequest.php` — add to `rules()`:

```php
            'is_starred' => ['sometimes', 'boolean'],
```

`src/Http/Resources/ProductResource.php` — add to `toArray()`:

```php
            'is_starred' => $this->is_starred,
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./vendor/bin/phpunit tests/Feature/ProductStarTest.php`
Expected: PASS, 1 test.

- [ ] **Step 6: Run the full gate**

Run: `make test && make stan && make style`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_13_000003_add_is_starred_to_inventory_products.php src/Models/Product.php src/Http/Requests/ProductRequest.php src/Http/Resources/ProductResource.php tests/Feature/ProductStarTest.php
git commit -m "feat: star a product

Staples are a household fact, not a personal one, so unlike the device-local
location/shelf favourites this lives on the server. A star is a marker and a
filter, never a sort."
```

---

## Task 10: Rewrite the locked rules the spec overrides

Three rules in `CLAUDE.md` now contradict the shipped code. Leaving them would mean the next agent reads "no soft deletes" while looking at a `deleted_at` column.

**Files:**
- Modify: `CLAUDE.md`, `docs/specs/data-model.md`, `docs/specs/api-contract.md`

- [ ] **Step 1: Fix the two contradicted hard rules in `CLAUDE.md`**

Under **Hard rules — LOCKED**, replace:

> - **Hard deletes, `ON DELETE CASCADE`** (location → shelves → products). No soft deletes.
> - **No roles/permissions** — all household members equal.

with:

```markdown
- **Soft deletes on the hierarchy** (locations/shelves/products carry `deleted_at`
  + `deletion_batch_id`). Reversed 2026-07-13: hard cascade deletes silently
  destroyed a location's whole subtree with no confirmation and no undo. The
  `ON DELETE CASCADE` FKs remain — a soft delete is an `UPDATE` and never fires
  them, and they stay correct for the retention purge (`inventory:deleted:prune`).
  Deleting a non-empty container REQUIRES an explicit strategy; the server never
  guesses. Households themselves are still hard-deleted when the last member leaves.
- **Roles/permissions: coming.** `HouseholdPolicy@restructure` is the seam — today
  it grants any member (all members still equal in practice). Owner/Admin/Member
  land in the roles spec; change that method body, not the call sites.
```

- [ ] **Step 2: Fix the scope guardrail**

Under **Scope guardrails**, the "no activity/audit log" clause still holds, but the roles ban does not. Ensure no line forbids roles/permissions; if one is present, remove it and point at the roles spec.

- [ ] **Step 3: Update the canonical specs**

`docs/specs/data-model.md` — add the new columns (`deleted_at`, `deletion_batch_id` on all three; `position` + `is_system` on locations; `is_system` on shelves; `is_starred` on products) and note the soft-delete posture.

`docs/specs/api-contract.md` — document the new/changed endpoints:

| Method | Path | Body | Notes |
|---|---|---|---|
| `PATCH` | `/households/{h}/locations/reorder` | `{ids: [int]}` | Full list, all-or-nothing |
| `PATCH` | `/households/{h}/locations/{l}/shelves/reorder` | `{ids: [int]}` | Full list, all-or-nothing |
| `DELETE` | `/households/{h}/locations/{l}` | `{strategy?, target_location_id?, deletion_batch_id}` | `strategy` required if the location holds shelves; `move_contents` \| `delete_contents` |
| `DELETE` | `/households/{h}/locations/{l}/shelves/{s}` | `{strategy?, target_shelf_id?, deletion_batch_id}` | `strategy` required if the shelf holds products; `move_products` \| `unsort_products` \| `delete_products` |
| `PATCH` | `/households/{h}/locations/{l}/shelves/{s}` | `{name?, position?, location_id?}` | `location_id` reparents; system shelves reject `name` |
| `POST` | `/households/{h}/restore/{batch}` | — | 200 `{restored: int}`, 409 if nothing to restore |
| `PATCH` | `/households/{h}/shelves/{s}/products/{p}` | `{..., is_starred?}` | |

Note that `deletion_batch_id` is **client-minted** and that new response fields (`position` on `LocationResource`, `is_system` on `ShelfResource`, `is_starred` on `ProductResource`) are additive and backward-compatible per the API-versioning rule.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md docs/specs/data-model.md docs/specs/api-contract.md
git commit -m "docs: reverse the no-soft-deletes and no-roles hard rules

Both were locked rules that the storage-architecture work deliberately
overrides (2026-07-13 user decision). Leaving them would have the next reader
trusting 'no soft deletes' while looking at a deleted_at column."
```

---

## Self-review

**Spec coverage.** Every backend requirement in the design maps to a task: schema (T1, T3, T4, T9) · reorder endpoints (T3) · reparenting (T6) · delete strategies at both levels (T5, T6) · Unsorted shelf (T4) · soft delete + client-minted batch id + restore (T1, T5, T6, T7) · 30-day purge (T8) · `canRestructure` seam (T2) · stars on products (T9) · broadcasting on bulk ops (T3, T5, T6, T7) · CLAUDE.md rewrite (T10).

**Not covered here, by design:** everything client-side — nav rework, edit mode, tabs⇄list toggle, collapsible groups, household edit page, the ordering rule as *rendered*, and the delete-strategy dialog. Those are the Android plan.

**Type consistency.** `HierarchyDeleter::deleteShelf(Household, Shelf, string, ?ShelfDeleteStrategy, ?int)` and `::deleteLocation(Household, StorageLocation, string, ?LocationDeleteStrategy, ?int)` are called with exactly those argument lists in T5/T6. `StorageLocation::unsortedShelf(): Shelf` is defined in T4 and consumed in T5. `ReorderRequest::ids(): list<int>` is defined in T3 and used in both reorder actions.

**Known ordering hazard.** Task 5 and Task 6 both add a `deletion_batch_id` requirement to endpoints `ResourceCrudTest` already exercises. Each task's gate step says so, but if you run tasks out of order, `make test` will fail on that file first.
