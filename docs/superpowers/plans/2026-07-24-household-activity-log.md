# Household Activity Log (MCP-only) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist an immutable, forever-retained audit trail of household mutations (create/update/delete on household/location/shelf/product, member add/remove/role-change/ownership-transfer) and expose it through two MCP-only surfaces — an admin cross-household endpoint and a per-household Sanctum-scoped endpoint — with no Android/web client ever calling either.

**Architecture:** A new `inventory_activity_log` table + `ActivityLogEntry` model. A new `RecordActivityLog` observer, registered on the same four models as the existing `BroadcastHouseholdChange` observer, captures create/update/delete automatically (mirroring how that observer already resolves `household_id` for each model type). The handful of call sites that bypass Eloquent events entirely (pivot writes in `MemberController`/`HouseholdController::join`, `Product::addStock()`/`removeStock()`, `HierarchyDeleter`'s query-builder cascades, `Restorer`'s query-builder restores) get an explicit `ActivityLog::record(...)` call, exactly the same pattern this codebase already uses for `HouseholdChanged::dispatch()` at those same sites. Two new read-only endpoints and two new MCP tools expose the log; nothing else changes.

**Tech Stack:** Laravel 11 (PHP 8.3), Eloquent model observers, PHPUnit + `RefreshDatabase`; Node/TypeScript MCP server (`inventory-mcp`) with `node --test`.

## Global Constraints

- MCP-only feature: no Android or web code changes in this plan, ever. If a task tempts you to touch `inventory-android` or a Blade view, stop — that's out of scope.
- Entries are immutable and kept forever — no update path, no prune command, no retention config.
- Pagination on both new endpoints follows the exact existing convention: `page`/`per_page` inputs, default 50, clamped `min(100, max(1, ...))`, response `meta` block with `page`/`per_page`/`total`/`last_page`.
- Cascading deletes get **one summarized entry per `deletion_batch_id`**, not one row per cascaded record.
- The manifest (`docs/specs/mcp-tools.json`) and both MCP surfaces (embedded PHP + standalone Node) must stay in sync — every tool addition touches all three.
- Spec: `docs/superpowers/specs/2026-07-24-household-activity-log-design.md`.

---

### Task 1: Migration + `ActivityLogEntry` model

**Files:**
- Create: `database/migrations/2026_07_24_000001_create_inventory_activity_log_table.php`
- Create: `src/Models/ActivityLogEntry.php`
- Test: `tests/Feature/ActivityLogEntryModelTest.php`

**Interfaces:**
- Produces: `ActivityLogEntry` model with `$table = 'inventory_activity_log'`, fillable `household_id`, `actor_id`, `action`, `subject_type`, `subject_id`, `subject_label`, `changes` (cast `array`), `created_at` only (`const UPDATED_AT = null`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Tests\TestCase;

class ActivityLogEntryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_and_casts_a_changes_array(): void
    {
        $entry = ActivityLogEntry::create([
            'household_id' => 1,
            'actor_id' => 2,
            'action' => 'product.updated',
            'subject_type' => 'Product',
            'subject_id' => 3,
            'subject_label' => 'Milk',
            'changes' => ['quantity' => ['from' => 3, 'to' => 0]],
        ]);

        $fresh = ActivityLogEntry::find($entry->id);

        $this->assertSame(['quantity' => ['from' => 3, 'to' => 0]], $fresh->changes);
        $this->assertNotNull($fresh->created_at);
        $this->assertArrayNotHasKey('updated_at', $fresh->getAttributes());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ActivityLogEntryModelTest.php`
Expected: FAIL — table `inventory_activity_log` doesn't exist / class `ActivityLogEntry` not found.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable, append-only audit trail — no updated_at, no retention/
        // prune. subject_label is a denormalized name snapshot so an entry
        // still reads sensibly after its target is later renamed/deleted.
        // subject_id is nullable for batch-summarized cascade-delete entries
        // (see HierarchyDeleter) that don't point at one single row.
        Schema::create('inventory_activity_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('household_id');
            $table->index(['household_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_activity_log');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit-trail row. Never updated after creation — see the
 * household-activity-log design spec for why (MCP-only feature, kept
 * forever, no prune job).
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string $subject_label
 * @property array<string, array{from: mixed, to: mixed}>|array{cascaded: array<string, int>}|null $changes
 */
class ActivityLogEntry extends Model
{
    protected $table = 'inventory_activity_log';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'changes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ActivityLogEntryModelTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_24_000001_create_inventory_activity_log_table.php src/Models/ActivityLogEntry.php tests/Feature/ActivityLogEntryModelTest.php
git commit -m "feat: add inventory_activity_log table and ActivityLogEntry model"
```

---

### Task 2: `ActivityLog` recorder helper + `RecordActivityLog` observer

**Files:**
- Create: `src/Support/ActivityLog.php`
- Create: `src/Observers/RecordActivityLog.php`
- Modify: `src/InventoryServiceProvider.php:99` area (extend `registerBroadcasting()`'s foreach, or add a sibling registration)
- Test: `tests/Feature/RecordActivityLogObserverTest.php`

**Interfaces:**
- Consumes: `ActivityLogEntry` (Task 1).
- Produces: `ActivityLog::record(int $householdId, ?int $actorId, string $action, string $subjectType, ?int $subjectId, string $subjectLabel, ?array $changes = null): void` — used by Task 3's manual call sites. `RecordActivityLog` observer class, registered on `Household`, `StorageLocation`, `Shelf`, `Product`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class RecordActivityLogObserverTest extends TestCase
{
    use RefreshDatabase;

    private function household(): Household
    {
        $user = User::factory()->create();
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);
        $this->actingAs($user);

        return $household;
    }

    public function test_creating_a_household_logs_household_created(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $household = Household::create(['name' => 'Casa', 'join_code' => 'XYZ789']);

        $entry = ActivityLogEntry::where('household_id', $household->getKey())->firstOrFail();
        $this->assertSame('household.created', $entry->action);
        $this->assertSame((int) $user->getKey(), $entry->actor_id);
        $this->assertSame('Casa', $entry->subject_label);
        $this->assertNull($entry->changes);
    }

    public function test_updating_a_product_logs_the_field_diff(): void
    {
        $household = $this->household();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => 'room']);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);
        $product = Product::create(['shelf_id' => $shelf->getKey(), 'name' => 'Milk', 'quantity' => 3]);

        $product->update(['quantity' => 0]);

        $entry = ActivityLogEntry::where('action', 'product.updated')->firstOrFail();
        $this->assertSame('Milk', $entry->subject_label);
        $this->assertSame(['from' => 3, 'to' => 0], $entry->changes['quantity']);
    }

    public function test_deleting_a_shelf_via_eloquent_logs_shelf_deleted(): void
    {
        $household = $this->household();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => 'room']);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);

        $shelf->delete();

        $entry = ActivityLogEntry::where('action', 'shelf.deleted')->firstOrFail();
        $this->assertSame('Pantry', $entry->subject_label);
    }
}
```

Adjust `StorageLocation::create`/`Shelf::create`/`Product::create` field names if this repo's factories differ — check `tests/Feature/AdminApiTest.php`'s use of `StorageType` enum; if `type` must be a `StorageType` enum instance rather than a string, use `StorageType::Room` instead of `'room'`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RecordActivityLogObserverTest.php`
Expected: FAIL — no rows in `inventory_activity_log` (observer doesn't exist yet).

- [ ] **Step 3: Write `ActivityLog` helper**

```php
<?php

namespace Spdotdev\Inventory\Support;

use Spdotdev\Inventory\Models\ActivityLogEntry;

/**
 * Single write path into inventory_activity_log — both the automatic
 * RecordActivityLog observer and the manual call sites that bypass Eloquent
 * events (pivot writes, HierarchyDeleter/Restorer's query-builder writes,
 * Product::addStock/removeStock) go through this, so the row shape can only
 * drift in one place.
 */
class ActivityLog
{
    /**
     * @param  array<string, array{from: mixed, to: mixed}>|array{cascaded: array<string, int>}|null  $changes
     */
    public static function record(
        int $householdId,
        ?int $actorId,
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $subjectLabel,
        ?array $changes = null,
    ): void {
        ActivityLogEntry::create([
            'household_id' => $householdId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'changes' => $changes,
        ]);
    }
}
```

- [ ] **Step 4: Write the observer**

```php
<?php

namespace Spdotdev\Inventory\Observers;

use Illuminate\Database\Eloquent\Model;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\ActivityLog;

/**
 * Mirrors BroadcastHouseholdChange's registration (same four models, same
 * created/updated/deleted hooks, same household-id resolution) so every
 * Eloquent-level mutation gets both a live-update ping AND a permanent audit
 * row, with no controller having to remember either. Like that observer,
 * this one is silent for query-builder writes (HierarchyDeleter's cascades,
 * Restorer's restores, Product::addStock/removeStock, pivot writes) — those
 * call ActivityLog::record() directly; see the design spec's Capture
 * mechanism section.
 */
class RecordActivityLog
{
    public function created(Model $model): void
    {
        $this->log($model, 'created', null);
    }

    public function updated(Model $model): void
    {
        $dirty = $model->getChanges();
        unset($dirty['updated_at']);

        if ($dirty === []) {
            return;
        }

        $changes = [];
        foreach ($dirty as $field => $to) {
            $changes[$field] = ['from' => $model->getOriginal($field), 'to' => $to];
        }

        $this->log($model, 'updated', $changes);
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', null);
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>|null  $changes
     */
    private function log(Model $model, string $verb, ?array $changes): void
    {
        $householdId = $this->householdId($model);

        if ($householdId === null) {
            return;
        }

        $subjectType = class_basename($model);
        $action = strtolower($subjectType).'.'.$verb;
        $label = (string) ($model->name ?? $model->getKey());

        ActivityLog::record(
            $householdId,
            auth()->id(),
            $action,
            $subjectType,
            $model->exists ? (int) $model->getKey() : null,
            $label,
            $changes,
        );
    }

    private function householdId(Model $model): ?int
    {
        return match (true) {
            $model instanceof Household => $model->exists ? (int) $model->getKey() : null,
            $model instanceof StorageLocation => (int) $model->household_id,
            $model instanceof Shelf => $model->location?->household_id !== null
                ? (int) $model->location->household_id
                : null,
            $model instanceof Product => $model->householdId(),
            default => null,
        };
    }
}
```

- [ ] **Step 5: Register the observer**

In `src/InventoryServiceProvider.php`, find `registerBroadcasting()` (around line 208):

```php
    private function registerBroadcasting(): void
    {
        foreach ([Household::class, StorageLocation::class, Shelf::class, Product::class] as $model) {
            $model::observe(BroadcastHouseholdChange::class);
        }
```

Add a sibling method and call it next to `$this->registerBroadcasting();` (line 99):

```php
        $this->registerBroadcasting();
        $this->registerActivityLog();
```

```php
    private function registerActivityLog(): void
    {
        foreach ([Household::class, StorageLocation::class, Shelf::class, Product::class] as $model) {
            $model::observe(RecordActivityLog::class);
        }
    }
```

Add the `use Spdotdev\Inventory\Observers\RecordActivityLog;` import alongside the existing `BroadcastHouseholdChange` import.

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RecordActivityLogObserverTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Support/ActivityLog.php src/Observers/RecordActivityLog.php src/InventoryServiceProvider.php tests/Feature/RecordActivityLogObserverTest.php
git commit -m "feat: auto-capture household/location/shelf/product mutations into the activity log"
```

---

### Task 3: Manual capture at the non-Eloquent-event call sites

**Files:**
- Modify: `src/Http/Controllers/Api/MemberController.php` (`update`, `destroy`, `transferOwnership`)
- Modify: `src/Http/Controllers/Api/HouseholdController.php` (`join`)
- Modify: `src/Models/Product.php` (`addStock`, `removeStock`)
- Modify: `src/Support/HierarchyDeleter.php` (`deleteShelf`, `deleteLocation`)
- Modify: `src/Support/Restorer.php` (`restore`)
- Test: `tests/Feature/ActivityLogManualCaptureTest.php`

**Interfaces:**
- Consumes: `ActivityLog::record(...)` (Task 2).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\ShelfDeleteStrategy;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Support\HierarchyDeleter;
use Spdotdev\Inventory\Support\Restorer;
use Spdotdev\Inventory\Tests\TestCase;

class ActivityLogManualCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function ownedHousehold(): array
    {
        $owner = User::factory()->create();
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        return [$household, $owner];
    }

    public function test_changing_a_member_role_logs_member_role_changed(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $member = User::factory()->create();
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($owner)
            ->patchJson("http://inventory.test/api/v1/households/{$household->getKey()}/members/{$member->getKey()}", ['role' => 'admin'])
            ->assertOk();

        $entry = ActivityLogEntry::where('action', 'member.role_changed')->firstOrFail();
        $this->assertSame((int) $owner->getKey(), $entry->actor_id);
        $this->assertSame(['from' => 'member', 'to' => 'admin'], $entry->changes['role']);
    }

    public function test_removing_a_member_logs_member_removed(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $member = User::factory()->create();
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($owner)
            ->deleteJson("http://inventory.test/api/v1/households/{$household->getKey()}/members/{$member->getKey()}")
            ->assertOk();

        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'member.removed']);
    }

    public function test_joining_a_household_logs_member_added(): void
    {
        [$household, ] = $this->ownedHousehold();
        $joiner = User::factory()->create();

        $this->actingAs($joiner)
            ->postJson('http://inventory.test/api/v1/households/join', ['code' => $household->join_code])
            ->assertOk();

        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'member.added', 'actor_id' => $joiner->getKey()]);
    }

    public function test_transferring_ownership_logs_household_ownership_transferred(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $member = User::factory()->create();
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($owner)
            ->postJson("http://inventory.test/api/v1/households/{$household->getKey()}/transfer-ownership", ['user_id' => $member->getKey()])
            ->assertOk();

        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'household.ownership_transferred']);
    }

    public function test_add_stock_logs_product_stock_added(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => 'room']);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);
        $product = Product::create(['shelf_id' => $shelf->getKey(), 'name' => 'Milk', 'quantity' => 0]);

        $this->actingAs($owner);
        $product->addStock(2, 10);

        $entry = ActivityLogEntry::where('action', 'product.stock_added')->firstOrFail();
        $this->assertSame(['from' => 0, 'to' => 2], $entry->changes['quantity']);
    }

    public function test_cascading_shelf_delete_logs_one_summarized_batch_entry(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => 'room']);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);
        Product::create(['shelf_id' => $shelf->getKey(), 'name' => 'Milk', 'quantity' => 1]);
        Product::create(['shelf_id' => $shelf->getKey(), 'name' => 'Eggs', 'quantity' => 1]);

        HierarchyDeleter::deleteShelf($household, $shelf, (string) \Illuminate\Support\Str::uuid(), ShelfDeleteStrategy::DeleteProducts, null, (int) $owner->getKey());

        $entry = ActivityLogEntry::where('action', 'shelf.deleted_batch')->firstOrFail();
        $this->assertSame('Pantry', $entry->subject_label);
        $this->assertSame(2, $entry->changes['cascaded']['products']);
    }

    public function test_restoring_a_batch_logs_a_restored_batch_entry(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => 'room']);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);
        $batch = (string) \Illuminate\Support\Str::uuid();
        HierarchyDeleter::deleteShelf($household, $shelf, $batch, ShelfDeleteStrategy::DeleteProducts, null, (int) $owner->getKey());

        Restorer::restore($household, $batch);

        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'shelf.restored_batch']);
    }
}
```

Adjust `StorageType`/enum usage if `type` needs to be a real enum instance rather than the string `'room'` (check any other test in this suite that creates a `StorageLocation` directly for the exact convention).

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/ActivityLogManualCaptureTest.php`
Expected: FAIL — no matching `inventory_activity_log` rows for any of the manual actions yet.

- [ ] **Step 3: Wire `MemberController`**

In `src/Http/Controllers/Api/MemberController.php`, add the import:

```php
use Spdotdev\Inventory\Support\ActivityLog;
```

In `update()`, after the pivot write and before the existing `HouseholdChanged::dispatch(...)` line:

```php
        $household->users()->updateExistingPivot($user->getKey(), ['role' => $data['role']]);

        ActivityLog::record(
            (int) $household->getKey(),
            $request->user()?->getKey() !== null ? (int) $request->user()->getKey() : null,
            'member.role_changed',
            'HouseholdUserPivot',
            (int) $user->getKey(),
            $user->name,
            ['role' => ['from' => $targetRole, 'to' => $data['role']]],
        );

        HouseholdChanged::dispatch((int) $household->getKey());
```

In `destroy()`, after `$household->users()->detach($user->getKey());`:

```php
        $household->users()->detach($user->getKey());

        ActivityLog::record(
            (int) $household->getKey(),
            $request->user()?->getKey() !== null ? (int) $request->user()->getKey() : null,
            'member.removed',
            'HouseholdUserPivot',
            (int) $user->getKey(),
            $user->name,
            null,
        );

        HouseholdChanged::dispatch((int) $household->getKey());
```

`destroy()` doesn't currently receive `Request $request` as a parameter — add it: `public function destroy(Request $request, Household $household, User $user): JsonResponse` (add `use Illuminate\Http\Request;` if not already imported — it already is, per `update()`'s signature).

In `transferOwnership()`, after the successful `OwnershipTransfer::transfer(...)` call:

```php
        abort_unless(OwnershipTransfer::transfer($household, $newOwner, $currentOwner), 409, 'Ownership has already been transferred.');

        ActivityLog::record(
            (int) $household->getKey(),
            (int) $currentOwner->getKey(),
            'household.ownership_transferred',
            'Household',
            (int) $household->getKey(),
            $household->name,
            ['owner' => ['from' => $currentOwner->name, 'to' => $newOwner->name]],
        );

        HouseholdChanged::dispatch((int) $household->getKey());
```

- [ ] **Step 4: Wire `HouseholdController::join`**

Add the import `use Spdotdev\Inventory\Support\ActivityLog;` to `src/Http/Controllers/Api/HouseholdController.php`. After the `syncWithoutDetaching` call:

```php
        $household->users()->syncWithoutDetaching([$user->getKey() => ['joined_at' => now()]]);

        ActivityLog::record(
            (int) $household->getKey(),
            (int) $user->getKey(),
            'member.added',
            'HouseholdUserPivot',
            (int) $user->getKey(),
            $user->name,
            null,
        );
```

- [ ] **Step 5: Wire `Product::addStock`/`removeStock`**

Add `use Spdotdev\Inventory\Support\ActivityLog;` to `src/Models/Product.php`. Update both methods to capture the before-value first:

```php
    public function addStock(int $amount, int $max): void
    {
        $before = $this->quantity;

        static::query()->whereKey($this->getKey())->update([
            'quantity' => DB::raw(
                'CASE WHEN quantity + '.$amount.' > '.$max.' THEN '.$max.' ELSE quantity + '.$amount.' END',
            ),
        ]);
        $this->refresh();

        ActivityLog::record(
            $this->householdId() ?? 0,
            auth()->id(),
            'product.stock_added',
            'Product',
            (int) $this->getKey(),
            $this->name,
            ['quantity' => ['from' => $before, 'to' => $this->quantity]],
        );

        $this->broadcastChange();
    }
```

```php
    public function removeStock(int $amount): void
    {
        $before = $this->quantity;

        static::query()->whereKey($this->getKey())->update([
            'quantity' => DB::raw(
                'CASE WHEN quantity < '.$amount.' THEN 0 ELSE quantity - '.$amount.' END',
            ),
        ]);
        $this->refresh();

        ActivityLog::record(
            $this->householdId() ?? 0,
            auth()->id(),
            'product.stock_removed',
            'Product',
            (int) $this->getKey(),
            $this->name,
            ['quantity' => ['from' => $before, 'to' => $this->quantity]],
        );

        $this->broadcastChange();
    }
```

`$this->householdId() ?? 0` guards the same theoretical null case `BroadcastHouseholdChange` already tolerates elsewhere; a real product always has a household, so this is defensive, not expected to trigger — but `ActivityLog::record`'s `householdId` parameter is a non-nullable `int`, so a fallback is required to type-check. If `householdId()` genuinely returns null for an orphaned product, the entry is still written against household id `0`, which the admin filter surface simply won't show — acceptable since `BroadcastHouseholdChange` treats the same case as "skip entirely" (its `ping()` no-ops on null), so this is already an edge case with no user-facing consequence either way.

- [ ] **Step 6: Wire `HierarchyDeleter::deleteShelf`/`deleteLocation`**

Add `use Spdotdev\Inventory\Support\ActivityLog;` to `src/Support/HierarchyDeleter.php`. In `deleteShelf()`, after the `DB::transaction(...)` call and before `HouseholdChanged::dispatch(...)`:

```php
        });

        ActivityLog::record(
            (int) $household->getKey(),
            $deletedBy,
            'shelf.deleted_batch',
            'Shelf',
            $originalShelfId,
            $shelf->name,
            ['cascaded' => ['products' => Product::withTrashed()->where('deletion_batch_id', $batchId)->count()]],
        );

        HouseholdChanged::dispatch((int) $household->getKey());
```

In `deleteLocation()`, after its `DB::transaction(...)` call and before `HouseholdChanged::dispatch(...)`:

```php
        });

        ActivityLog::record(
            (int) $household->getKey(),
            $deletedBy,
            'location.deleted_batch',
            'StorageLocation',
            $originalLocationId,
            $location->name,
            ['cascaded' => [
                'shelves' => Shelf::withTrashed()->where('deletion_batch_id', $batchId)->count(),
                'products' => Product::withTrashed()->where('deletion_batch_id', $batchId)->count(),
            ]],
        );

        HouseholdChanged::dispatch((int) $household->getKey());
```

Both counts are queried by `deletion_batch_id` after commit — simpler and just as accurate as threading a counter through the transaction closure, since every row this batch touched (soft-deleted or reparented-with-batch-id) already carries the same `$batchId`.

- [ ] **Step 7: Wire `Restorer::restore`**

Add `use Spdotdev\Inventory\Support\ActivityLog;` and `use Spdotdev\Inventory\Models\StorageLocation;` (if not already imported) to `src/Support/Restorer.php`. In `restore()`, after the `DB::transaction(...)` call, before the existing `if ($result['status'] === self::STATUS_RESTORED)` broadcast block, expand it:

```php
        $result = DB::transaction(function () use ($household, $batch): array {
            return self::restoreWithinTransaction($household, $batch);
        });

        if ($result['status'] === self::STATUS_RESTORED) {
            ActivityLog::record(
                (int) $household->getKey(),
                auth()->id(),
                'household.restored_batch',
                'Household',
                (int) $household->getKey(),
                $household->name,
                ['restored' => ['count' => $result['restored']]],
            );

            HouseholdChanged::dispatch((int) $household->getKey());
        }

        return $result;
```

This uses `household.restored_batch` rather than a per-type action (`shelf.restored_batch`) since a single batch can span locations/shelves/products together — one restore gesture, one summarized entry, mirroring the delete side's "one entry per batch" rule. (The test in Step 1 above asserting `shelf.restored_batch` must be corrected to assert `household.restored_batch` instead — fix it now before running.)

- [ ] **Step 8: Fix the Step-1 test's restored-batch action name**

In `tests/Feature/ActivityLogManualCaptureTest.php`, change:

```php
        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'shelf.restored_batch']);
```

to:

```php
        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'household.restored_batch']);
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/ActivityLogManualCaptureTest.php`
Expected: PASS

- [ ] **Step 10: Run the full test suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: All PASS (watch specifically for `MemberController`/`HouseholdController`/`Product`/`HierarchyDeleter`/`Restorer` existing tests — none of their assertions should change, since every edit above is additive).

- [ ] **Step 11: Commit**

```bash
git add src/Http/Controllers/Api/MemberController.php src/Http/Controllers/Api/HouseholdController.php src/Models/Product.php src/Support/HierarchyDeleter.php src/Support/Restorer.php tests/Feature/ActivityLogManualCaptureTest.php
git commit -m "feat: manually capture activity-log entries at non-Eloquent-event call sites"
```

---

### Task 4: Admin cross-household endpoint

**Files:**
- Create: `src/Http/Resources/ActivityLogEntryResource.php`
- Modify: `src/Http/Controllers/Api/AdminController.php` (add `listActivity`)
- Modify: `routes/api.php` (add admin route)
- Test: `tests/Feature/AdminActivityApiTest.php`

**Interfaces:**
- Consumes: `ActivityLogEntry` (Task 1).
- Produces: `GET /api/v1/admin/activity` — admin-token-gated, filters `household_id`/`actor_id`/`action`/`subject_type`/`from`/`to`, paginated (`page`/`per_page`, default 50/max 100), response `{"data": [...], "meta": {...}}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Tests\TestCase;

class AdminActivityApiTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1/admin';

    private string $token = 'super-secret-admin-token';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('inventory.admin_token', $this->token);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->getJson("{$this->base}/activity")->assertStatus(401);
    }

    public function test_lists_entries_across_households_newest_first(): void
    {
        ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => 'A', 'created_at' => now()->subMinutes(5)]);
        ActivityLogEntry::create(['household_id' => 2, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 2, 'subject_label' => 'B', 'created_at' => now()]);

        $response = $this->getJson("{$this->base}/activity", $this->auth())->assertOk();

        $this->assertSame('B', $response->json('data.0.subject_label'));
        $this->assertSame('A', $response->json('data.1.subject_label'));
    }

    public function test_filters_by_household_id(): void
    {
        ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => 'A']);
        ActivityLogEntry::create(['household_id' => 2, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 2, 'subject_label' => 'B']);

        $response = $this->getJson("{$this->base}/activity?household_id=2", $this->auth())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('B', $response->json('data.0.subject_label'));
    }

    public function test_filters_by_action(): void
    {
        ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => 'A']);
        ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.deleted', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => 'A']);

        $response = $this->getJson("{$this->base}/activity?action=household.deleted", $this->auth())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('household.deleted', $response->json('data.0.action'));
    }

    public function test_respects_per_page_and_clamps_to_one_hundred(): void
    {
        for ($i = 0; $i < 15; $i++) {
            ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => "H{$i}"]);
        }

        $response = $this->getJson("{$this->base}/activity?per_page=10", $this->auth())->assertOk();
        $this->assertCount(10, $response->json('data'));
        $this->assertSame(10, $response->json('meta.per_page'));

        $clamped = $this->getJson("{$this->base}/activity?per_page=500", $this->auth())->assertOk();
        $this->assertSame(100, $clamped->json('meta.per_page'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AdminActivityApiTest.php`
Expected: FAIL — route `admin/activity` doesn't exist (404).

- [ ] **Step 3: Write the Resource**

```php
<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\ActivityLogEntry;

/**
 * @mixin ActivityLogEntry
 */
class ActivityLogEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'household_id' => $this->household_id,
            'actor_id' => $this->actor_id,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject_label' => $this->subject_label,
            'changes' => $this->changes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Add `AdminController::listActivity`**

Add imports `use Spdotdev\Inventory\Http\Resources\ActivityLogEntryResource;` and `use Spdotdev\Inventory\Models\ActivityLogEntry;` to `src/Http/Controllers/Api/AdminController.php`. Add the method (new `// ─── Activity ───` section, e.g. before `// ─── Helpers ───`):

```php
    // ─── Activity ────────────────────────────────────────────────────────────

    public function listActivity(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

        $query = ActivityLogEntry::query()->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        if ($request->filled('household_id')) {
            $query->where('household_id', (int) $request->input('household_id'));
        }
        if ($request->filled('actor_id')) {
            $query->where('actor_id', (int) $request->input('actor_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', (string) $request->input('action'));
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', (string) $request->input('subject_type'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', (string) $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', (string) $request->input('to'));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => ActivityLogEntryResource::collection($paginator->items())->resolve(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
```

- [ ] **Step 5: Add the route**

In `routes/api.php`, inside the existing `Route::middleware(['inventory.admin', 'throttle:inventory-admin'])->prefix('admin')->group(function () { ... })` block, add:

```php
            Route::get('activity', [AdminController::class, 'listActivity']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/AdminActivityApiTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Http/Resources/ActivityLogEntryResource.php src/Http/Controllers/Api/AdminController.php routes/api.php tests/Feature/AdminActivityApiTest.php
git commit -m "feat: add GET /admin/activity cross-household endpoint"
```

---

### Task 5: Household-scoped Sanctum endpoint

**Files:**
- Create: `src/Http/Controllers/Api/ActivityLogController.php`
- Modify: `routes/api.php` (add household-scoped route)
- Test: `tests/Feature/HouseholdActivityApiTest.php`

**Interfaces:**
- Consumes: `ActivityLogEntryResource` (Task 4).
- Produces: `GET /api/v1/households/{household}/activity` — any member, filters minus `household_id` (implicit), same pagination convention.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdActivityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_view_their_household_activity(): void
    {
        $user = User::factory()->create();
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        ActivityLogEntry::create(['household_id' => $household->getKey(), 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => $household->getKey(), 'subject_label' => 'Casa']);
        ActivityLogEntry::create(['household_id' => 999, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 999, 'subject_label' => 'Other']);

        $response = $this->actingAs($user)
            ->getJson("http://inventory.test/api/v1/households/{$household->getKey()}/activity")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Casa', $response->json('data.0.subject_label'));
    }

    public function test_a_non_member_gets_a_404_not_a_403(): void
    {
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->getJson("http://inventory.test/api/v1/households/{$household->getKey()}/activity")
            ->assertStatus(404);
    }

    public function test_filters_by_action_within_the_household(): void
    {
        $user = User::factory()->create();
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        ActivityLogEntry::create(['household_id' => $household->getKey(), 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => $household->getKey(), 'subject_label' => 'Casa']);
        ActivityLogEntry::create(['household_id' => $household->getKey(), 'action' => 'member.added', 'subject_type' => 'HouseholdUserPivot', 'subject_id' => $user->getKey(), 'subject_label' => $user->name]);

        $response = $this->actingAs($user)
            ->getJson("http://inventory.test/api/v1/households/{$household->getKey()}/activity?action=member.added")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('member.added', $response->json('data.0.action'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/HouseholdActivityApiTest.php`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spdotdev\Inventory\Http\Resources\ActivityLogEntryResource;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Models\Household;

/**
 * Read-only per-household activity feed. MCP-only surface (see the
 * household-activity-log design spec) — Android/web never call this;
 * `household.member` gates it exactly like DeletedBatchController, so any
 * member (not only Owner/Admin) can view their own household's history.
 */
class ActivityLogController
{
    public function __invoke(Request $request, Household $household): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

        $query = ActivityLogEntry::query()
            ->where('household_id', $household->getKey())
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('actor_id')) {
            $query->where('actor_id', (int) $request->input('actor_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', (string) $request->input('action'));
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', (string) $request->input('subject_type'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', (string) $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', (string) $request->input('to'));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => ActivityLogEntryResource::collection($paginator->items())->resolve(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, add the import `use Spdotdev\Inventory\Http\Controllers\Api\ActivityLogController;` and, inside the existing `Route::middleware('household.member')->scopeBindings()->group(function () { ... })` block, add (near `deleted`):

```php
                Route::get('households/{household}/activity', ActivityLogController::class)->name('inventory.api.activity');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/HouseholdActivityApiTest.php`
Expected: PASS

- [ ] **Step 6: Run the full PHP suite + quality gates**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Http/Controllers/Api/ActivityLogController.php routes/api.php tests/Feature/HouseholdActivityApiTest.php
git commit -m "feat: add GET /households/{household}/activity member-scoped endpoint"
```

---

### Task 6: Embedded PHP MCP tool + manifest

**Files:**
- Modify: `docs/specs/mcp-tools.json` (append `list_activity_log`, `get_household_activity`)
- Create: `src/Mcp/Tools/ListActivityLogTool.php`
- Modify: `src/Mcp/InventoryAdminServer.php` (register the new tool)
- Test: existing `tests/Feature/McpToolManifestTest.php` (no edit needed — it reads the manifest + reflects `InventoryAdminServer::$tools` automatically)

**Interfaces:**
- Consumes: `AdminController::listActivity`'s query logic (Task 4), replicated directly against Eloquent (embedded tools query the ORM in-process, they don't call the HTTP endpoint — same pattern as `ListUsersTool`).
- Produces: embedded tool wire name `list-activity-log-tool` → manifest key `list_activity_log`.

- [ ] **Step 1: Add manifest entries**

In `docs/specs/mcp-tools.json`, inside the `"tools"` array, add two entries (after the existing `update_app_release` entry, before the closing `]`):

```json
        {
            "key": "list_activity_log",
            "scope": "admin",
            "destructive": false,
            "params": [
                { "name": "household_id", "type": "integer", "required": false },
                { "name": "actor_id", "type": "integer", "required": false },
                { "name": "action", "type": "string", "required": false },
                { "name": "subject_type", "type": "string", "required": false },
                { "name": "from", "type": "string", "required": false },
                { "name": "to", "type": "string", "required": false },
                { "name": "page", "type": "integer", "required": false },
                { "name": "per_page", "type": "integer", "required": false }
            ]
        },
        {
            "key": "get_household_activity",
            "scope": "household",
            "destructive": false,
            "params": [
                { "name": "household_id", "type": "integer", "required": true },
                { "name": "actor_id", "type": "integer", "required": false },
                { "name": "action", "type": "string", "required": false },
                { "name": "subject_type", "type": "string", "required": false },
                { "name": "from", "type": "string", "required": false },
                { "name": "to", "type": "string", "required": false },
                { "name": "page", "type": "integer", "required": false },
                { "name": "per_page", "type": "integer", "required": false }
            ]
        }
```

Bump `"version": 2` to `"version": 3` at the top of the file.

- [ ] **Step 2: Write the failing test**

The existing `tests/Feature/McpToolManifestTest.php` already asserts the embedded server's tool set matches the manifest's admin-scoped entries exactly — no new test file is needed. Just run it now to confirm it fails.

Run: `vendor/bin/phpunit tests/Feature/McpToolManifestTest.php`
Expected: FAIL — `list_activity_log` is in the manifest (admin scope) but not yet in `InventoryAdminServer::$tools`.

- [ ] **Step 3: Write `ListActivityLogTool`**

```php
<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Http\Resources\ActivityLogEntryResource;
use Spdotdev\Inventory\Models\ActivityLogEntry;

#[Description('List activity-log entries across all households, newest first. Filterable by household_id, actor_id, action, subject_type, and date range.')]
class ListActivityLogTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'household_id' => $schema->integer()->description('Filter to one household.'),
            'actor_id' => $schema->integer()->description('Filter to entries by one acting user.'),
            'action' => $schema->string()->description('Filter to one action, e.g. product.deleted.'),
            'subject_type' => $schema->string()->description('Filter to one subject type, e.g. Product.'),
            'from' => $schema->string()->description('ISO 8601 date/time lower bound (inclusive).'),
            'to' => $schema->string()->description('ISO 8601 date/time upper bound (inclusive).'),
            'page' => $schema->integer()->description('Page number, 1-indexed (default 1).'),
            'per_page' => $schema->integer()->description('Rows per page, max 100 (default 50).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 50)));

        $query = ActivityLogEntry::query()->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        if ($request->get('household_id') !== null) {
            $query->where('household_id', (int) $request->get('household_id'));
        }
        if ($request->get('actor_id') !== null) {
            $query->where('actor_id', (int) $request->get('actor_id'));
        }
        if ($request->get('action') !== null) {
            $query->where('action', (string) $request->get('action'));
        }
        if ($request->get('subject_type') !== null) {
            $query->where('subject_type', (string) $request->get('subject_type'));
        }
        if ($request->get('from') !== null) {
            $query->where('created_at', '>=', (string) $request->get('from'));
        }
        if ($request->get('to') !== null) {
            $query->where('created_at', '<=', (string) $request->get('to'));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return Response::json([
            'data' => ActivityLogEntryResource::collection($paginator->items())->resolve(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
```

- [ ] **Step 4: Register the tool**

In `src/Mcp/InventoryAdminServer.php`, add the import `use Spdotdev\Inventory\Mcp\Tools\ListActivityLogTool;` and append it to the `$tools` array (order matches the manifest — since `list_activity_log` is the manifest's last admin entry, append it last):

```php
    protected array $tools = [
        ListUsersTool::class,
        SearchUsersTool::class,
        GetUserTool::class,
        DeleteUserTool::class,
        ListHouseholdsTool::class,
        GetHouseholdTool::class,
        DeleteHouseholdTool::class,
        ListAppReleasesTool::class,
        CreateAppReleaseTool::class,
        UpdateAppReleaseTool::class,
        ListActivityLogTool::class,
    ];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/McpToolManifestTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add docs/specs/mcp-tools.json src/Mcp/Tools/ListActivityLogTool.php src/Mcp/InventoryAdminServer.php
git commit -m "feat: add list_activity_log embedded MCP tool + manifest entries"
```

---

### Task 7: Standalone `inventory-mcp` server tools

**Files (repo: `/home/dev/inventory/inventory-mcp`):**
- Modify: `src/server.ts` (add `list_activity_log`, `get_household_activity`)
- Modify: `test/server.test.mjs` (bump `EXPECTED_TOOLS`/its count assertion, add a new household-scope tool group)

**Interfaces:**
- Consumes: `adminFetch(path)` / `authFetch(path)` helpers already defined in `src/server.ts`.
- Produces: two new registered tools, wired into the existing test harness's `ALL_NEW_TOOLS` map.

- [ ] **Step 1: Write the failing tests**

In `test/server.test.mjs`, update the admin-tools section:

```javascript
const EXPECTED_TOOLS = {
  list_users: { args: {}, method: "GET", path: "/users" },
  search_users: { args: { q: "a b%" }, method: "GET", path: "/users/search?q=a+b%25" },
  get_user: { args: { id: 7 }, method: "GET", path: "/users/7" },
  delete_user: { args: { id: 7 }, method: "DELETE", path: "/users/7" },
  list_households: { args: {}, method: "GET", path: "/households" },
  get_household: { args: { id: 3 }, method: "GET", path: "/households/3" },
  delete_household: { args: { id: 3 }, method: "DELETE", path: "/households/3" },
  list_app_releases: { args: {}, method: "GET", path: "/app-releases" },
  create_app_release: {
    args: { version_code: 22, version_name: "0.1.21", changelog: "test", download_url: "https://example.test/app.apk" },
    method: "POST",
    path: "/app-releases",
  },
  update_app_release: {
    args: { id: 5, publish: true },
    method: "PATCH",
    path: "/app-releases/5",
  },
  list_activity_log: { args: {}, method: "GET", path: "/activity" },
};

test("exposes exactly the eleven admin tools", async () => {
  const client = await connectedClient(stubFetch());
  const { tools } = await client.listTools();
  assert.deepEqual(
    tools.map((t) => t.name).sort(),
    Object.keys(EXPECTED_TOOLS).sort()
  );
});
```

(Only the test name and the `list_activity_log` entry/count change; every other `EXPECTED_TOOLS` line and the `read-only mode registers only the list/search/get tools` test below it stay as-is — `list_activity_log` is read-only, so it's naturally included in that existing filter since it doesn't start with `delete_`/equal `create_app_release`/`update_app_release`.)

Add a new household-scope tool entry to `RESTORE_TOOLS` (or a new small group merged into `ALL_NEW_TOOLS` — simplest is adding directly into `RESTORE_TOOLS` next to its sibling `export_household`/`list_deleted`, since it's the same read-only "household scope, GET, no body" shape):

```javascript
const RESTORE_TOOLS = {
  export_household: {
    args: { household_id: 1 },
    method: "GET",
    path: "/households/1/export",
  },
  list_deleted: {
    args: { household_id: 1 },
    method: "GET",
    path: "/households/1/deleted",
  },
  get_household_activity: {
    args: { household_id: 1 },
    method: "GET",
    path: "/households/1/activity",
  },
  restore_batch: {
    args: { household_id: 1, batch: "b7f6c1de-9a2e-4c3a-9e1e-1a2b3c4d5e6f" },
    method: "POST",
    path: "/households/1/restore/b7f6c1de-9a2e-4c3a-9e1e-1a2b3c4d5e6f",
  },
};
```

And extend the read-only-mode test that currently lists the four read tools explicitly:

```javascript
test("read-only mode registers only list_members/get_household_invite/list_deleted/export_household/get_household_activity, not the write tools", async () => {
  const client = await connectedClient(stubFetch(), { userToken: USER_TOKEN, readOnly: true });
  const { tools } = await client.listTools();
  const names = tools.map((t) => t.name);
  assert.equal(names.includes("list_members"), true);
  assert.equal(names.includes("get_household_invite"), true);
  assert.equal(names.includes("list_deleted"), true);
  assert.equal(names.includes("export_household"), true);
  assert.equal(names.includes("get_household_activity"), true);
  const readTools = ["list_members", "get_household_invite", "list_deleted", "export_household", "get_household_activity"];
  for (const name of Object.keys(ALL_NEW_TOOLS)) {
    if (readTools.includes(name)) continue;
    assert.equal(names.includes(name), false, `${name} should be absent in read-only mode`);
  }
});
```

Also extend the readOnlyHint assertion test:

```javascript
  assert.equal(byName.list_deleted.annotations?.readOnlyHint, true);
  assert.equal(byName.export_household.annotations?.readOnlyHint, true);
  assert.equal(byName.get_household_activity.annotations?.readOnlyHint, true);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npm test`
Expected: FAIL — `list_activity_log` and `get_household_activity` tools not found (404 on `listTools`/no matching tool to call).

- [ ] **Step 3: Add `list_activity_log` to `src/server.ts`**

Find the "─── Users ───" section (around line 115, right after `list_users`/`search_users`/before `get_user`) — actually simplest to add it as its own section right after the `admin` tools block ends and before the `if (userToken)` block begins (around line 300, just before line 301 `if (userToken) {`). Add:

```typescript
  // ─── Activity log (admin) ───────────────────────────────────────────────────

  server.registerTool(
    "list_activity_log",
    {
      description:
        "List activity-log entries across all households, newest first. Filterable by household_id, actor_id, action, subject_type, and date range.",
      inputSchema: {
        household_id: z.number().int().positive().optional().describe("Filter to one household"),
        actor_id: z.number().int().positive().optional().describe("Filter to entries by one acting user"),
        action: z.string().optional().describe("Filter to one action, e.g. product.deleted"),
        subject_type: z.string().optional().describe("Filter to one subject type, e.g. Product"),
        from: z.string().optional().describe("ISO 8601 date/time lower bound (inclusive)"),
        to: z.string().optional().describe("ISO 8601 date/time upper bound (inclusive)"),
        page: z.number().int().positive().optional().describe("Page number, 1-indexed (default 1)"),
        per_page: z.number().int().positive().max(100).optional().describe("Rows per page, max 100 (default 50)"),
      },
      annotations: { readOnlyHint: true },
    },
    async ({ household_id, actor_id, action, subject_type, from, to, page, per_page }) => {
      const params = new URLSearchParams();
      if (household_id !== undefined) params.set("household_id", String(household_id));
      if (actor_id !== undefined) params.set("actor_id", String(actor_id));
      if (action !== undefined) params.set("action", action);
      if (subject_type !== undefined) params.set("subject_type", subject_type);
      if (from !== undefined) params.set("from", from);
      if (to !== undefined) params.set("to", to);
      if (page !== undefined) params.set("page", String(page));
      if (per_page !== undefined) params.set("per_page", String(per_page));
      const qs = params.toString();
      return asText(await adminFetch(`/activity${qs ? `?${qs}` : ""}`));
    },
  );
```

- [ ] **Step 4: Add `get_household_activity` inside the `if (userToken)` block**

Inside the `if (userToken) { ... }` block (starts at line 301), find the "Recently deleted / restore" section (around line 524, right after `export_household`, before `list_deleted`) and add:

```typescript
    server.registerTool(
      "get_household_activity",
      {
        description:
          "List a household's own activity-log entries, newest first. Filterable by actor_id, action, subject_type, and date range.",
        inputSchema: {
          household_id: z.number().int().positive().describe("Household ID"),
          actor_id: z.number().int().positive().optional().describe("Filter to entries by one acting user"),
          action: z.string().optional().describe("Filter to one action, e.g. product.deleted"),
          subject_type: z.string().optional().describe("Filter to one subject type, e.g. Product"),
          from: z.string().optional().describe("ISO 8601 date/time lower bound (inclusive)"),
          to: z.string().optional().describe("ISO 8601 date/time upper bound (inclusive)"),
          page: z.number().int().positive().optional().describe("Page number, 1-indexed (default 1)"),
          per_page: z.number().int().positive().max(100).optional().describe("Rows per page, max 100 (default 50)"),
        },
        annotations: { readOnlyHint: true },
      },
      async ({ household_id, actor_id, action, subject_type, from, to, page, per_page }) => {
        const params = new URLSearchParams();
        if (actor_id !== undefined) params.set("actor_id", String(actor_id));
        if (action !== undefined) params.set("action", action);
        if (subject_type !== undefined) params.set("subject_type", subject_type);
        if (from !== undefined) params.set("from", from);
        if (to !== undefined) params.set("to", to);
        if (page !== undefined) params.set("page", String(page));
        if (per_page !== undefined) params.set("per_page", String(per_page));
        const qs = params.toString();
        return asText(await authFetch(`/households/${household_id}/activity${qs ? `?${qs}` : ""}`));
      },
    );
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npm test`
Expected: PASS (all tests, including the new/updated ones from Step 1).

- [ ] **Step 6: Run conformance check against the manifest**

Run: `npm run conformance`
Expected: PASS — `list_activity_log` and `get_household_activity` both present with matching scope/params against `docs/specs/mcp-tools.json` (path: `../inventory-laravel/docs/specs/mcp-tools.json` or wherever `scripts/conformance.mjs` resolves it from; verify the resolved path if this fails with "file not found" rather than a mismatch).

- [ ] **Step 7: Commit**

```bash
git add src/server.ts test/server.test.mjs
git commit -m "feat: add list_activity_log and get_household_activity MCP tools"
```

---

### Task 8: Final cross-repo verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full inventory-laravel suite + quality gates**

Run (from `/home/dev/inventory/inventory-laravel`): `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all PASS.

- [ ] **Step 2: Run the full inventory-mcp suite + conformance**

Run (from `/home/dev/inventory/inventory-mcp`): `npm test && npm run conformance`
Expected: all PASS.

- [ ] **Step 3: Update BACKLOG.md / ROADMAP.md**

Add a `Done` entry to `/home/dev/inventory/inventory-laravel/BACKLOG.md` under its existing `## Done` section:

```markdown
- ✅ `2026-07-24` — **Household activity/audit log (MCP-only).** Immutable
  `inventory_activity_log` trail of household/location/shelf/product
  create/update/delete + member add/remove/role-change/ownership-transfer,
  captured automatically via `RecordActivityLog` (mirroring
  `BroadcastHouseholdChange`'s registration) plus explicit
  `ActivityLog::record()` calls at the handful of sites that bypass Eloquent
  events. Two read-only surfaces, both MCP-only (no Android/web UI):
  `GET /admin/activity` (cross-household, admin token) and
  `GET /households/{household}/activity` (any member, Sanctum). Kept forever,
  no prune job. Spec:
  `docs/superpowers/specs/2026-07-24-household-activity-log-design.md`.
```

Add the corresponding entry to `/home/dev/inventory/inventory-mcp/BACKLOG.md`'s `## Ideas — parking lot` section, converting it to done:

```markdown
- ✅ **Activity log tools — shipped 2026-07-24.** `list_activity_log` (admin
  scope, cross-household) and `get_household_activity` (household scope, any
  member) added, backed by the new `inventory_activity_log` table. Neither is
  referenced by Android/web — MCP-only per design.
```

- [ ] **Step 4: Commit the backlog updates**

```bash
cd /home/dev/inventory/inventory-laravel && git add BACKLOG.md && git commit -m "docs: mark household activity log shipped in BACKLOG.md"
cd /home/dev/inventory/inventory-mcp && git add BACKLOG.md && git commit -m "docs: mark activity log MCP tools shipped in BACKLOG.md"
```
