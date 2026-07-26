# Notification Feed + Expanded Notifications Menu Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A server-side per-user notification feed (member joined / role changed / activity digest) polled by Android WorkManager, plus a low-stock daily reminder, weekly summary, and an app-update toggle — all controllable in More → Notifications.

**Architecture:** Laravel gains an `inventory_notifications` table written at existing `ActivityLog::record` call sites, a user-scoped `GET /notifications?after=<id>` endpoint, and `GET /low-stock/count` (sibling of `missing-items/count`). Android adds a 6-hour `NotificationFeedWorker` (cursor in SharedPrefs, advanced only after posting), a `LowStockCheckWorker` cloned from the missing-items reminder, and four new notification channels. No FCM, no Firebase — the feed schema is FCM-ready later.

**Tech Stack:** Laravel package (Orchestra Testbench tests, no factories), Kotlin/Compose + Hilt + Retrofit + kotlinx.serialization + WorkManager (JUnit4 JVM tests, hand-written fakes).

**Spec:** `docs/superpowers/specs/2026-07-26-notification-feed-design.md`. Two amendments made by this plan (Task 12 updates the spec): (1) activity digest covers **deletes and member departures only** — move batches are indistinguishable from generic product updates server-side and are excluded v1; (2) the low-stock notification opens the app without a deep link v1 (same as app-update notifications).

## Global Constraints

- Laravel repo: `/home/dev/inventory/inventory-laravel`. Android repo: `/home/dev/inventory/inventory-android`. Commit in the repo you touched; never mix repos in one commit.
- All new tables prefixed `inventory_`; anonymous-class migrations.
- Feed endpoints are **user-scoped under `auth:sanctum`, NOT household-URL-scoped** — they go next to `missing-items/count` in `routes/api.php`, outside the `household.member` group.
- Android request/response DTOs: kotlinx `@Serializable`; the app's Json uses `explicitNulls = true`, `encodeDefaults = false` — response DTOs are unaffected, but never add request DTOs here without byte-level tests (this feature has none: all calls are GET).
- Defaults (LOCKED by user): low-stock reminder **on at 18:00**; member-joined + role-changes **on**; activity digest **off**; weekly summary **off, Sunday 18:00**; app-update notification **on**.
- Every new EN string gets an NL sibling in `values-nl/strings.xml`, wired with `stringResource()` in the same commit (`StringResourceUsageTest` enforces).
- Laravel gate: `composer test` (and `vendor/bin/pint` if dirty). Android gates: `./gradlew testDebugUnitTest ktlintCheck detekt`.
- Android workers: `@HiltWorker` + `@AssistedInject`, scheduled from `InventoryApp` (KEEP) and rescheduled from ViewModels (CANCEL_AND_REENQUEUE), exactly like the missing-items reminder.

---

# Part A — Laravel (inventory-laravel)

### Task 1: `inventory_notifications` table + `AppNotification` model + prune command

**Files:**
- Create: `database/migrations/2026_07_26_000001_create_inventory_notifications_table.php`
- Create: `src/Models/AppNotification.php`
- Create: `src/Console/Commands/PruneNotificationsCommand.php`
- Modify: `src/InventoryServiceProvider.php` (~lines 184-199: command registration + schedule)
- Test: `tests/Feature/PruneNotificationsCommandTest.php`

**Interfaces:**
- Produces: model `Spdotdev\Inventory\Models\AppNotification` (table `inventory_notifications`; fillable `user_id`, `household_id`, `type`, `payload`, `read_at`; casts `payload => array`, `read_at => datetime`; `created_at` only, no `updated_at`). Command `inventory:notifications:prune` deleting rows older than 30 days.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('household_id')->nullable();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            // The feed reader is always "this user's rows with id > cursor".
            $table->index(['user_id', 'id']);
            $table->index('created_at'); // prune scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_notifications');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-user notification feed row (delivered today by Android's polling
 * worker; the schema is deliberately FCM-ready). Not the activity log:
 * inventory_activity_log stays MCP-only; this table holds coarse,
 * user-addressed rows only.
 */
class AppNotification extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'inventory_notifications';

    protected $fillable = ['user_id', 'household_id', 'type', 'payload', 'read_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'read_at' => 'datetime'];
    }
}
```

- [ ] **Step 3: Write the failing prune test**

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\AppNotification;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class PruneNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_rows_older_than_30_days_and_keeps_newer(): void
    {
        $user = User::query()->create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => bcrypt('secret123')]);
        $old = AppNotification::query()->create(['user_id' => $user->id, 'type' => 'activity', 'payload' => ['count' => 1]]);
        $old->newQuery()->whereKey($old->id)->update(['created_at' => now()->subDays(31)]);
        $fresh = AppNotification::query()->create(['user_id' => $user->id, 'type' => 'activity', 'payload' => ['count' => 2]]);

        $this->artisan('inventory:notifications:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('inventory_notifications', ['id' => $old->id]);
        $this->assertDatabaseHas('inventory_notifications', ['id' => $fresh->id]);
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/PruneNotificationsCommandTest.php`
Expected: FAIL — command `inventory:notifications:prune` not found.

- [ ] **Step 5: Write the command and register it**

`src/Console/Commands/PruneNotificationsCommand.php`:

```php
<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Spdotdev\Inventory\Models\AppNotification;

class PruneNotificationsCommand extends Command
{
    protected $signature = 'inventory:notifications:prune';

    protected $description = 'Delete notification feed rows older than 30 days.';

    public function handle(): int
    {
        $deleted = AppNotification::query()->where('created_at', '<', now()->subDays(30))->delete();
        $this->info("Pruned {$deleted} notifications.");

        return self::SUCCESS;
    }
}
```

In `src/InventoryServiceProvider.php`, add `PruneNotificationsCommand::class` to the existing `$this->commands([...])` array (next to `PruneDeletedCommand`/`PruneClientErrorsCommand`, ~line 184) and add inside the existing `callAfterResolving(Schedule::class, ...)` closure:

```php
$schedule->command('inventory:notifications:prune')->dailyAt('04:29');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/PruneNotificationsCommandTest.php`
Expected: PASS (2 assertions).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_26_000001_create_inventory_notifications_table.php src/Models/AppNotification.php src/Console/Commands/PruneNotificationsCommand.php src/InventoryServiceProvider.php tests/Feature/PruneNotificationsCommandTest.php
git commit -m "feat: notification feed table, model and prune command"
```

---

### Task 2: `NotificationFeed` writer + hooks at the six event sites

**Files:**
- Create: `src/Support/NotificationFeed.php`
- Modify: `src/Http/Controllers/Api/HouseholdController.php` (`join` ~line 69, `leave` ~line 147)
- Modify: `src/Http/Controllers/Api/MemberController.php` (`update` ~line 48, `destroy` ~line 77, `transferOwnership` ~line 119)
- Modify: `src/Support/HierarchyDeleter.php` (after the `ActivityLog::record` calls at ~line 136 and ~line 310)
- Test: `tests/Feature/NotificationFeedWritersTest.php`

**Interfaces:**
- Consumes: `AppNotification` from Task 1; existing `Household::users()` belongs-to-many with pivot `role`.
- Produces: class `Spdotdev\Inventory\Support\NotificationFeed` with static methods:
  - `toUser(int $userId, ?int $householdId, string $type, array $payload): void`
  - `toManagers(Household $household, int $exceptUserId, string $type, array $payload): void` — every member whose pivot role is `owner` or `admin`, except `$exceptUserId`.
  - `toOtherMembers(Household $household, ?int $actorId, string $type, array $payload): void` — every member except the actor.
- Feed types written: `member_joined` (payload `{name}`), `role_changed` (payload `{role: {from, to}}` or `{role: {to: "owner"}}`), `activity` (payload `{actor, kind, count}` where `kind` ∈ `items_deleted` | `member_left` | `member_removed`).

- [ ] **Step 1: Write the failing writer tests**

`tests/Feature/NotificationFeedWritersTest.php` — build users/households exactly like `MissingItemsApiTest` (no factories; `Sanctum::actingAs`; base URL `http://inventory.test/api/v1`). Cover, one test method each:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class NotificationFeedWritersTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function user(string $name): User
    {
        return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => bcrypt('secret123')]);
    }

    public function test_join_notifies_owner_and_admin_but_not_joiner_or_member(): void
    {
        $owner = $this->user('Owner');
        $admin = $this->user('Admin');
        $member = $this->user('Member');
        $joiner = $this->user('Joiner');
        $h = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $h->users()->attach($owner, ['role' => 'owner']);
        $h->users()->attach($admin, ['role' => 'admin']);
        $h->users()->attach($member, ['role' => 'member']);

        Sanctum::actingAs($joiner);
        $this->postJson("{$this->base}/households/join", ['join_code' => 'AAAA1111'])->assertOk();

        $this->assertDatabaseHas('inventory_notifications', ['user_id' => $owner->id, 'household_id' => $h->id, 'type' => 'member_joined']);
        $this->assertDatabaseHas('inventory_notifications', ['user_id' => $admin->id, 'type' => 'member_joined']);
        $this->assertDatabaseMissing('inventory_notifications', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('inventory_notifications', ['user_id' => $joiner->id]);
    }

    public function test_rejoin_is_idempotent_and_writes_nothing(): void { /* join twice; assert count of member_joined rows for owner == 1 */ }

    public function test_role_change_notifies_affected_user_only(): void { /* PATCH members/{user} role admin; assert role_changed row for target, none for actor */ }

    public function test_ownership_transfer_notifies_new_owner(): void { /* POST transfer-ownership; assert role_changed row for new owner with payload role.to == owner */ }

    public function test_member_removed_writes_activity_for_remaining_members_not_actor_or_removed(): void { /* DELETE members/{user}; assert activity rows kind member_removed */ }

    public function test_leave_writes_activity_for_remaining_members(): void { /* POST households/{h}/leave; assert activity kind member_left for stayers */ }

    public function test_shelf_batch_delete_writes_one_activity_row_per_other_member(): void { /* delete a shelf with 2 products, strategy delete; assert one activity row (kind items_deleted, count >= 1) for the other member, none for actor */ }
}
```

Fill each skeleton body with real request/assert code following the first test's style — the exact routes are in `routes/api.php` (`households/join`, `households/{household}/members/{user}`, `households/{household}/transfer-ownership`, `households/{household}/leave`, shelf delete under the `household.member` group). For the shelf test, build household→location→shelf→products with `Model::query()->create` like existing hierarchy tests do (crib the setup from the existing `HierarchyDeleter`/shelf-delete feature test — find it with `grep -rl "deleted_batch" tests/Feature`).

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/NotificationFeedWritersTest.php`
Expected: FAIL — no rows in `inventory_notifications` (writers don't exist yet).

- [ ] **Step 3: Write `NotificationFeed`**

```php
<?php

namespace Spdotdev\Inventory\Support;

use Spdotdev\Inventory\Models\AppNotification;
use Spdotdev\Inventory\Models\Household;

/**
 * Single write path into inventory_notifications (mirrors ActivityLog's
 * design). Rows are user-addressed and coarse — never a full audit trail;
 * the MCP-only activity log keeps that role.
 */
class NotificationFeed
{
    /** @param array<string, mixed> $payload */
    public static function toUser(int $userId, ?int $householdId, string $type, array $payload): void
    {
        AppNotification::query()->create([
            'user_id' => $userId,
            'household_id' => $householdId,
            'type' => $type,
            'payload' => $payload,
        ]);
    }

    /** Owners and admins of the household, except $exceptUserId. */
    public static function toManagers(Household $household, int $exceptUserId, string $type, array $payload): void
    {
        $ids = $household->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->whereKeyNot($exceptUserId)
            ->pluck('inventory_users.id');
        foreach ($ids as $id) {
            self::toUser((int) $id, (int) $household->getKey(), $type, $payload);
        }
    }

    /** Every current member except the actor. */
    public static function toOtherMembers(Household $household, ?int $actorId, string $type, array $payload): void
    {
        $query = $household->users();
        if ($actorId !== null) {
            $query = $query->whereKeyNot($actorId);
        }
        foreach ($query->pluck('inventory_users.id') as $id) {
            self::toUser((int) $id, (int) $household->getKey(), $type, $payload);
        }
    }
}
```

- [ ] **Step 4: Add the six hook calls**

Each goes immediately after the existing `ActivityLog::record` call at that site (same placement discipline: after the transaction where the site is transactional).

1. `HouseholdController::join`, inside `if (! $wasAlreadyMember)`:
```php
NotificationFeed::toManagers($household, (int) $user->getKey(), 'member_joined', ['name' => $user->name]);
```
2. `HouseholdController::leave` (after its `ActivityLog::record`; the user is already detached, so `toOtherMembers` naturally excludes them — pass the leaver's id anyway for clarity). Skip when the household was hard-deleted as last-member-left (place the call before the last-member branch or guard on `$household->exists`):
```php
NotificationFeed::toOtherMembers($household, (int) $user->getKey(), 'activity', ['actor' => $user->name, 'kind' => 'member_left', 'count' => 1]);
```
3. `MemberController::update`:
```php
NotificationFeed::toUser((int) $user->getKey(), (int) $household->getKey(), 'role_changed', ['role' => ['from' => $targetRole, 'to' => $data['role']]]);
```
4. `MemberController::destroy`:
```php
NotificationFeed::toOtherMembers($household, $request->user()?->getKey() !== null ? (int) $request->user()->getKey() : null, 'activity', ['actor' => (string) $request->user()?->name, 'kind' => 'member_removed', 'count' => 1]);
```
5. `MemberController::transferOwnership`:
```php
NotificationFeed::toUser((int) $newOwner->getKey(), (int) $household->getKey(), 'role_changed', ['role' => ['to' => 'owner']]);
```
6. `HierarchyDeleter` — after BOTH `ActivityLog::record` calls (shelf ~line 136, location ~line 310). The deleter knows `$deletedBy` (nullable actor id) and the cascaded counts it just computed for the log call; reuse them (extract the count into a local variable instead of recomputing):
```php
NotificationFeed::toOtherMembers($household, $deletedBy, 'activity', [
    'actor' => $deletedBy !== null ? (string) User::query()->whereKey($deletedBy)->value('name') : null,
    'kind' => 'items_deleted',
    'count' => 1 + $cascadedCount, // the container itself + cascaded children
]);
```
Add the needed `use Spdotdev\Inventory\Support\NotificationFeed;` (and `User` in HierarchyDeleter) imports.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/NotificationFeedWritersTest.php`
Expected: PASS (all 7 tests). Then run the FULL suite — these controllers have many existing tests: `composer test`. Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Support/NotificationFeed.php src/Http/Controllers/Api/HouseholdController.php src/Http/Controllers/Api/MemberController.php src/Support/HierarchyDeleter.php tests/Feature/NotificationFeedWritersTest.php
git commit -m "feat: write notification feed rows on join, role change, transfer, removal, leave and batch delete"
```

---

### Task 3: `GET /notifications` feed endpoint

**Files:**
- Create: `src/Http/Controllers/Api/NotificationsController.php`
- Modify: `routes/api.php` (~line 89, next to `missing-items/count`, under `auth:sanctum`, OUTSIDE `household.member`)
- Test: `tests/Feature/NotificationsApiTest.php`

**Interfaces:**
- Consumes: `AppNotification` (Task 1).
- Produces: `GET /api/v1/notifications?after=<id>` → up to 50 rows with `id > after`, ascending by id, caller's rows only. Response:
```json
{"data":[{"id":7,"type":"member_joined","household":{"id":3,"name":"Home"},"payload":{"name":"Joiner"},"created_at":"..."}],"meta":{"last_id":7}}
```
`meta.last_id` = highest returned id, or the request's `after` when the page is empty. `household` is null for household-less rows. `after` defaults to 0; non-numeric/negative treated as 0.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/NotificationsApiTest.php` (same conventions as `MissingItemsApiTest`): (a) returns own rows ascending with correct shape and `meta.last_id`; (b) `after` cursor excludes rows `<= after`; (c) never returns another user's rows even in a shared household; (d) 401 unauthenticated; (e) page caps at 50 (create 55 rows, assert 50 returned and `last_id` = 50th id); (f) empty page echoes `after` back as `last_id`. Create rows directly with `AppNotification::query()->create([...])`.

- [ ] **Step 2: Run to verify FAIL** — `vendor/bin/phpunit tests/Feature/NotificationsApiTest.php` → 404s (route missing).

- [ ] **Step 3: Implement**

Controller:

```php
<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spdotdev\Inventory\Models\AppNotification;
use Spdotdev\Inventory\Models\Household;

class NotificationsController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(Request $request): JsonResponse
    {
        $after = max(0, (int) $request->input('after', 0));

        $rows = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get();

        $households = Household::query()
            ->whereIn('id', $rows->pluck('household_id')->filter()->unique())
            ->pluck('name', 'id');

        return response()->json([
            'data' => $rows->map(fn (AppNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'household' => $n->household_id !== null
                    ? ['id' => $n->household_id, 'name' => $households[$n->household_id] ?? null]
                    : null,
                'payload' => $n->payload,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
            'meta' => ['last_id' => $rows->last()?->id ?? $after],
        ]);
    }
}
```

Route (next to `missing-items/count`):

```php
Route::get('notifications', [NotificationsController::class, 'index'])->name('inventory.api.notifications.index');
```

- [ ] **Step 4: Run to verify PASS** — `vendor/bin/phpunit tests/Feature/NotificationsApiTest.php`, then `composer test`.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/Api/NotificationsController.php routes/api.php tests/Feature/NotificationsApiTest.php
git commit -m "feat: user-scoped notification feed endpoint with after-cursor"
```

---

### Task 4: `GET /low-stock/count` endpoint

**Files:**
- Modify: `src/Http/Controllers/Api/MissingItemsController.php` → no; Create: `src/Http/Controllers/Api/LowStockController.php`
- Modify: `routes/api.php` (next to `missing-items/count`)
- Test: `tests/Feature/LowStockApiTest.php`

**Interfaces:**
- Produces: `GET /api/v1/low-stock/count` → `{"data":{"count":N}}` — products across ALL the caller's households where `low_stock_threshold` is not null AND `quantity <= low_stock_threshold`, EXCLUDING missing items (`is_mandatory AND quantity = 0`) — this mirrors the Android client's `HierarchyStore.classify` definition exactly (low-stock excludes already-missing).

- [ ] **Step 1: Write the failing tests** — `tests/Feature/LowStockApiTest.php`, cloned from `MissingItemsApiTest` structure: (a) counts at/below threshold across two households; (b) excludes products with null threshold, above threshold, and mandatory-at-zero (that one is "missing", not "low"); (c) excludes other users' households; (d) 401 unauthenticated. A non-mandatory product at quantity 0 with a threshold IS counted (it is low, not missing).

- [ ] **Step 2: Run to verify FAIL** — 404.

- [ ] **Step 3: Implement**

```php
<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spdotdev\Inventory\Models\Product;

class LowStockController extends Controller
{
    public function count(Request $request): JsonResponse
    {
        $count = Product::query()
            ->whereNotNull('low_stock_threshold')
            ->whereColumn('quantity', '<=', 'low_stock_threshold')
            ->whereNot(function ($query) {
                $query->where('is_mandatory', true)->where('quantity', 0);
            })
            ->whereHas('shelf.location.household.users', function ($query) use ($request) {
                $query->where('inventory_users.id', $request->user()->id);
            })
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }
}
```

Route: `Route::get('low-stock/count', [LowStockController::class, 'count'])->name('inventory.api.low-stock.count');`

- [ ] **Step 4: Run to verify PASS**, then `composer test`.
- [ ] **Step 5: Commit** — `git add src/Http/Controllers/Api/LowStockController.php routes/api.php tests/Feature/LowStockApiTest.php && git commit -m "feat: account-wide low-stock count endpoint"`

---

# Part B — Android (inventory-android)

All paths below relative to `app/src/main/java/dev/scuttle/inventory/` (tests: `app/src/test/java/dev/scuttle/inventory/`) unless noted.

### Task 5: API layer — DTOs, Retrofit interfaces, repositories, DI

**Files:**
- Create: `data/dto/NotificationFeedResponse.kt`, `data/dto/LowStockCountResponse.kt`
- Create: `data/api/NotificationsApi.kt`, `data/api/LowStockApi.kt`
- Create: `data/notifications/NotificationFeedRepository.kt` + `NotificationFeedRepositoryImpl.kt`, `data/lowstock/LowStockRepository.kt` + `LowStockRepositoryImpl.kt`
- Modify: `di/NetworkModule.kt` (two new `@Provides`), `di/RepositoryModule.kt` (two new `@Binds`)
- Test: `app/src/test/java/dev/scuttle/inventory/data/notifications/NotificationFeedRepositoryTest.kt`

**Interfaces:**
- Produces:
```kotlin
@Serializable data class FeedHouseholdDto(val id: Long, val name: String? = null)
@Serializable data class FeedEventDto(
    val id: Long, val type: String, val household: FeedHouseholdDto? = null,
    val payload: JsonObject? = null, @SerialName("created_at") val createdAt: String? = null,
)
@Serializable data class NotificationFeedMeta(@SerialName("last_id") val lastId: Long)
@Serializable data class NotificationFeedResponse(val data: List<FeedEventDto>, val meta: NotificationFeedMeta)
@Serializable data class LowStockCountResponse(val data: LowStockCountData)
@Serializable data class LowStockCountData(val count: Int)

interface NotificationsApi { @GET("notifications") suspend fun feed(@Query("after") after: Long): NotificationFeedResponse }
interface LowStockApi { @GET("low-stock/count") suspend fun count(): LowStockCountResponse }

interface NotificationFeedRepository { suspend fun fetch(after: Long): NotificationFeedResponse? } // null on any error
interface LowStockRepository { suspend fun count(): Int? } // null on any error
```
- Impls mirror `MissingItemsRepositoryImpl` exactly: `@Inject constructor(private val api: ...)`, try/catch → `Log.w` + null. DI mirrors the missing-items providers/binds verbatim.

- [ ] **Step 1: Write the failing repository test** — `NotificationFeedRepositoryTest.kt`, same style as `MissingItemsRepositoryTest` (fake api object implementing the interface; one test: success passes response through including `meta.lastId`; one test: api throwing → returns null). Use `kotlinx.coroutines.test.runTest`.
- [ ] **Step 2: Run** `./gradlew testDebugUnitTest --tests "*NotificationFeedRepositoryTest*"` → FAIL (unresolved references).
- [ ] **Step 3: Implement** all files per the Interfaces block; copy the structure of `MissingItemsApi`/`MissingItemsCountResponse`/`MissingItemsRepositoryImpl` and the corresponding `NetworkModule`/`RepositoryModule` entries.
- [ ] **Step 4: Run** the test again → PASS; then `./gradlew ktlintCheck detekt` → clean.
- [ ] **Step 5: Commit** — `git commit -m "feat: notification feed and low-stock count API clients"` (add the 9 touched files).

---

### Task 6: Settings stores — notification prefs, low-stock reminder settings, feed state

**Files:**
- Create: `data/settings/NotificationPrefs.kt`, `data/settings/NotificationPrefsStore.kt`, `data/settings/SharedPrefsNotificationPrefsStore.kt`
- Create: `data/settings/FeedStateStore.kt`, `data/settings/SharedPrefsFeedStateStore.kt`
- Modify: `di/StorageModule.kt` (three new `@Provides @Singleton`)
- Test: `app/src/test/java/dev/scuttle/inventory/data/settings/NotificationPrefsTest.kt`

**Interfaces:**
- Produces:
```kotlin
data class NotificationPrefs(
    val appUpdatesEnabled: Boolean = true,
    val householdEventsEnabled: Boolean = true,   // member_joined + role_changed
    val activityDigestEnabled: Boolean = false,
    val weeklySummaryEnabled: Boolean = false,
    val weeklyDayOfWeek: Int = java.util.Calendar.SUNDAY, // Calendar constant, 1..7
    val weeklyHour: Int = 18,
    val weeklyMinute: Int = 0,
    val lowStockEnabled: Boolean = true,
    val lowStockHour: Int = 18,
    val lowStockMinute: Int = 0,
)
interface NotificationPrefsStore { fun get(): NotificationPrefs; fun set(prefs: NotificationPrefs) }

data class FeedState(val lastSeenId: Long = 0L, val lastWeeklySummaryAtMillis: Long = 0L)
interface FeedStateStore { fun get(): FeedState; fun set(state: FeedState) }
```
- Both SharedPrefs impls use the existing `"inventory_settings"` prefs file (same as `SharedPrefsReminderSettingsStore`), key prefix `notification_prefs_` / `feed_state_`. The low-stock reminder reuses the existing `ReminderSettings` shape via `NotificationPrefs.lowStock*` fields — no separate store.
- Helper on prefs for the scheduler: `fun NotificationPrefs.lowStockReminderSettings() = ReminderSettings(lowStockEnabled, lowStockHour, lowStockMinute)` (top-level extension in `NotificationPrefs.kt`).

- [ ] **Step 1: Write the failing test** — pure-JVM test of `SharedPrefsNotificationPrefsStore` is not possible (needs Context); instead test defaults + round-trip through a hand-written `FakeNotificationPrefsStore` is pointless. Test what's real: defaults of the data classes and the `lowStockReminderSettings()` mapping:
```kotlin
class NotificationPrefsTest {
    @Test fun defaultsMatchSpec() {
        val p = NotificationPrefs()
        assertTrue(p.appUpdatesEnabled); assertTrue(p.householdEventsEnabled)
        assertFalse(p.activityDigestEnabled); assertFalse(p.weeklySummaryEnabled)
        assertEquals(Calendar.SUNDAY, p.weeklyDayOfWeek); assertEquals(18, p.weeklyHour)
        assertTrue(p.lowStockEnabled); assertEquals(18, p.lowStockHour); assertEquals(0, p.lowStockMinute)
    }
    @Test fun lowStockMapsToReminderSettings() {
        assertEquals(ReminderSettings(true, 18, 0), NotificationPrefs().lowStockReminderSettings())
    }
}
```
- [ ] **Step 2: Run** → FAIL (unresolved). **Step 3: Implement** the five files + the three `StorageModule` providers (pattern: `provideReminderSettingsStore`). **Step 4: Run** test + `ktlintCheck detekt` → PASS. **Step 5: Commit** — `git commit -m "feat: notification preference and feed-state stores"`.

---

### Task 7: Low-stock daily reminder (worker + notifier + scheduler)

**Files:**
- Create: `work/LowStockCheckWorker.kt`, `work/LowStockNotifier.kt`, `work/LowStockReminderScheduler.kt`
- Modify: `InventoryApp.kt` (`onCreate`: create channel + `ensureScheduled`)
- Test: `app/src/test/java/dev/scuttle/inventory/work/LowStockReminderSchedulerTest.kt`

**Interfaces:**
- Consumes: `LowStockRepository` (Task 5), `NotificationPrefsStore.get().lowStockReminderSettings()` (Task 6), existing `ReminderScheduler.initialDelayMillis` pattern.
- Produces: channel `"low_stock_reminder"`, notification id `1003`, unique work name `"low_stock_check"`. `open class LowStockReminderScheduler @Inject constructor()` with `reschedule(context, settings: ReminderSettings)` and `ensureScheduled(context, settings)` — byte-for-byte the `ReminderScheduler` pattern (24 h periodic, `CANCEL_AND_REENQUEUE` on reschedule, `KEEP` on ensure, cancel when disabled). Rather than duplicating `initialDelayMillis`, call the existing `ReminderScheduler().initialDelayMillis(hour, minute)` — no: keep them decoupled; copy the function and its test (it is 15 lines; the two reminders must be able to drift).

- [ ] **Step 1: Write the failing scheduler test** — clone `ReminderSchedulerTest.kt`'s three `initialDelayMillis` cases (later today / rolls to tomorrow / exactly-now rolls) against `LowStockReminderScheduler`.
- [ ] **Step 2: Run** → FAIL. 
- [ ] **Step 3: Implement.** `LowStockNotifier.kt` clones `MissingItemsNotifier.kt`: `createLowStockNotificationChannel(context)` (strings `notification_channel_low_stock_name/_description` — added in Task 11 with ALL new strings; use them here, Task 11 lands before any build gate needs strings? No — strings must exist to compile. Add ONLY the strings this task references, EN + NL, in this task; Task 11 adds the settings-screen strings), `postLowStockNotification(context, count)` with plural `notification_low_stock_title` ("%d item is running low"/"%d items are running low"; NL "%d item is bijna op"/"%d items zijn bijna op"), permission guard, `setAutoCancel`, plain `MainActivity` intent WITHOUT a navigate-to extra (v1: opens the app). `LowStockCheckWorker` clones `MissingItemsCheckWorker` (`@HiltWorker`, injects `LowStockRepository`; `count() ?: return success`; post; success). `InventoryApp.onCreate()`: `createLowStockNotificationChannel(this)` and `lowStockReminderScheduler.ensureScheduled(this, notificationPrefsStore.get().lowStockReminderSettings())` (inject both).
- [ ] **Step 4: Run** scheduler test + full `./gradlew testDebugUnitTest ktlintCheck detekt` → PASS (StringResourceUsageTest passes because every added string is referenced).
- [ ] **Step 5: Commit** — `git commit -m "feat: daily low-stock reminder notification (default on, 18:00)"`.

---

### Task 8: Feed digestion — pure grouping + weekly-summary due logic

**Files:**
- Create: `work/FeedDigester.kt`, `work/WeeklySummaryPlanner.kt`
- Test: `app/src/test/java/dev/scuttle/inventory/work/FeedDigesterTest.kt`, `.../WeeklySummaryPlannerTest.kt`

**Interfaces:**
- Consumes: `FeedEventDto`, `NotificationPrefs`.
- Produces (all pure JVM, no Android imports — this is the unit-testable core):
```kotlin
sealed interface PlannedNotification {
    data class MemberJoined(val eventId: Long, val memberName: String?, val householdName: String?) : PlannedNotification
    data class RoleChanged(val eventId: Long, val newRole: String?, val householdName: String?) : PlannedNotification
    data class ActivityDigest(val householdId: Long, val householdName: String?, val changeCount: Int) : PlannedNotification
}
object FeedDigester {
    /** Groups a poll page: per-event for member_joined/role_changed (when
     * householdEventsEnabled), one ActivityDigest per household summing
     * payload counts (when activityDigestEnabled). Unknown types are
     * dropped silently (forward compatibility) but still advance the cursor. */
    fun digest(events: List<FeedEventDto>, prefs: NotificationPrefs): List<PlannedNotification>
}
object WeeklySummaryPlanner {
    /** True when prefs.weeklySummaryEnabled and the most recent configured
     * day+time boundary at or before nowMillis is strictly after
     * lastPostedMillis. Uses Calendar; caller passes now for testability. */
    fun isDue(prefs: NotificationPrefs, lastPostedMillis: Long, now: java.util.Calendar): Boolean
}
```
- Digest details: `MemberJoined.memberName` from `payload["name"]` (JsonPrimitive contentOrNull); `RoleChanged.newRole` from `payload["role"]["to"]`; `ActivityDigest.changeCount` sums each activity event's `payload["count"]` (default 1); events with null household group under householdId 0 / name null.

- [ ] **Step 1: Write failing tests.** `FeedDigesterTest`: mixed page produces per-event + digest correctly; digest sums counts per household; householdEventsEnabled=false drops joined/role events; activityDigestEnabled=false drops activity; unknown type dropped without throwing. `WeeklySummaryPlannerTest`: due when boundary passed since last post; not due before boundary; not due twice in one week (lastPosted after boundary); disabled → never due. Build `FeedEventDto` payloads with `buildJsonObject { put("name", "Ann") }`; Calendars with explicit `set` fixtures like `ReminderSchedulerTest`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** both objects. **Step 4: Run** → PASS + lint gates. **Step 5: Commit** — `git commit -m "feat: feed digestion and weekly summary due-time logic"`.

---

### Task 9: `NotificationFeedWorker` + feed notifiers + 6-hour scheduling

**Files:**
- Create: `work/NotificationFeedWorker.kt`, `work/FeedNotifier.kt`
- Modify: `InventoryApp.kt` (create 3 channels; `scheduleNotificationFeedPoll()` next to `scheduleAppUpdateCheck()`)
- Test: `app/src/test/java/dev/scuttle/inventory/work/NotificationFeedWorkerLogicTest.kt`

**Interfaces:**
- Consumes: Tasks 5, 6, 8; missing-items + low-stock repositories for the summary counts; `HierarchyStore` product totals are NOT used (server counts only: summary shows missing + low-stock; product total comes from the households the dashboard already counts — v1 the summary body is "X missing, Y running low" — see strings note below; the spec's "N products" is dropped to avoid a heavyweight dashboard dependency in a worker; note this in the Task 12 spec amendment).
- Produces: channels `"household_events"`, `"household_activity"`, `"weekly_summary"`; unique periodic work `"notification_feed_poll"` every 6 h (`FEED_POLL_INTERVAL_HOURS = 6L`), network-constrained, `KEEP` from app boot. `FeedNotifier.kt` exposes `createFeedNotificationChannels(context)` + `postPlanned(context, planned: PlannedNotification)` + `postWeeklySummary(context, missing: Int, lowStock: Int)`; notification ids: household events `(2000 + eventId % 1000).toInt()`, digest `(3000 + householdId % 1000).toInt()`, weekly summary `1005`.
- Worker `doWork()` (extract the decision core into `internal fun planPoll(...)` if needed for tests; the flow):
```kotlin
val prefs = prefsStore.get()
val state = feedStateStore.get()
val page = feedRepository.fetch(after = state.lastSeenId) ?: return Result.retry()
FeedDigester.digest(page.data, prefs).forEach { postPlanned(applicationContext, it) }
feedStateStore.set(state.copy(lastSeenId = page.meta.lastId))   // advance ONLY after posting
if (WeeklySummaryPlanner.isDue(prefs, state.lastWeeklySummaryAtMillis, Calendar.getInstance())) {
    val missing = missingItemsRepository.count()
    val low = lowStockRepository.count()
    if (missing != null && low != null) {
        postWeeklySummary(applicationContext, missing, low)
        feedStateStore.set(feedStateStore.get().copy(lastWeeklySummaryAtMillis = System.currentTimeMillis()))
    }
}
return Result.success()
```
- Strings added in this task (EN + NL, all referenced): channel names/descriptions ×3, `notification_member_joined_title` ("%1$s joined %2$s"), `notification_role_changed_title` ("Your role in %1$s is now %2$s"), plural `notification_activity_digest_title` ("%d change in %s"/"%d changes in %s" — use `getQuantityString` with two args), `notification_weekly_summary_title` ("Your week"), `notification_weekly_summary_body` ("%1$d missing, %2$d running low"). Null household/member names fall back to `R.string.notification_fallback_household` ("your household") / omit the name gracefully.

- [ ] **Step 1: Write the failing logic test** — `NotificationFeedWorkerLogicTest` tests the cursor rule without Android: extract `internal fun nextFeedState(state: FeedState, fetched: NotificationFeedResponse?): FeedState?` (null = retry, else advanced state) into the worker's file as a top-level internal function and test: null fetch → null; success → lastSeenId becomes meta.lastId; empty page keeps cursor (meta echoes `after`).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** worker + notifier + `InventoryApp` wiring (channels created in `onCreate` beside the existing two; `scheduleNotificationFeedPoll()` clones `scheduleAppUpdateCheck()` with a `Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED)` — note: check whether `scheduleAppUpdateCheck` already sets constraints and match its style). **Step 4: Run** full unit tests + lint gates → PASS. **Step 5: Commit** — `git commit -m "feat: 6-hourly notification feed poll with digests and weekly summary"`.

---

### Task 10: App-update notification toggle

**Files:**
- Modify: `work/AppUpdateCheckWorker.kt`
- Test: `app/src/test/java/dev/scuttle/inventory/work/AppUpdateCheckWorkerTest.kt` (create or extend if exists — check first)

**Interfaces:**
- Consumes: `NotificationPrefsStore` (Task 6).
- Produces: the worker injects `NotificationPrefsStore` and returns `Result.success()` WITHOUT calling the repository when `appUpdatesEnabled` is false. The on-open update dialog is untouched (it lives outside this worker).

- [ ] **Step 1: Write failing test** for the extracted guard: add top-level `internal fun shouldCheckForUpdates(prefs: NotificationPrefs): Boolean = prefs.appUpdatesEnabled` — trivial, so instead test at worker granularity only if an existing worker test harness exists; otherwise the guard function test is the honest minimum:
```kotlin
@Test fun disabledPrefSkipsCheck() { assertFalse(shouldCheckForUpdates(NotificationPrefs(appUpdatesEnabled = false))) }
@Test fun defaultChecks() { assertTrue(shouldCheckForUpdates(NotificationPrefs())) }
```
- [ ] **Step 2-4:** FAIL → implement (worker calls the guard first) → PASS + lint gates.
- [ ] **Step 5: Commit** — `git commit -m "feat: user toggle gates background app-update notifications"`.

---

### Task 11: Notifications settings screen — five sections + ViewModels + strings

**Files:**
- Create: `ui/settings/NotificationPrefsViewModel.kt`, `ui/settings/LowStockReminderViewModel.kt`
- Modify: `ui/settings/NotificationsScreen.kt` (grow to five sections)
- Modify: `app/src/main/res/values/strings.xml`, `app/src/main/res/values-nl/strings.xml`
- Test: `app/src/test/java/dev/scuttle/inventory/ui/settings/NotificationPrefsViewModelTest.kt`, `.../LowStockReminderViewModelTest.kt`

**Interfaces:**
- Consumes: stores (Task 6), `LowStockReminderScheduler` (Task 7), existing `ReminderViewModel` untouched.
- Produces:
```kotlin
@HiltViewModel class LowStockReminderViewModel @Inject constructor(
    @ApplicationContext context: Context, store: NotificationPrefsStore, scheduler: LowStockReminderScheduler,
) : ViewModel()
// exposes settings: StateFlow<ReminderSettings> (mapped from prefs), setEnabled(Boolean), setTime(Int, Int)
// each setter: store.set(prefs.copy(lowStock...)), update flow, scheduler.reschedule(context, mapped)

@HiltViewModel class NotificationPrefsViewModel @Inject constructor(store: NotificationPrefsStore) : ViewModel()
// exposes prefs: StateFlow<NotificationPrefs>; setHouseholdEvents / setActivityDigest /
// setWeeklySummaryEnabled / setWeeklyDay(Int) / setWeeklyTime(Int, Int) / setAppUpdates — store.set + flow update.
// No rescheduling needed: the 6-h poll always runs; prefs gate what it posts.
```
- Screen layout (existing patterns only — section header `Text(titleMedium, heading())`, label+`Switch` `Row`, `TextButton` → `AlertDialog(TimePicker)`; wrap the Column in `verticalScroll(rememberScrollState())` since it no longer fits):
  1. **Missing items** — unchanged rows, existing `ReminderViewModel`.
  2. **Low stock** — `settings_low_stock_section` "Low stock reminder" / toggle `settings_low_stock_toggle` "Remind me daily" / `settings_low_stock_time` "Reminder time: %1$02d:%2$02d" — via `LowStockReminderViewModel` (reuses the existing shared `_time_confirm`/`_time_cancel` strings for its dialog buttons).
  3. **Household** — `settings_household_notifications_section` "Household"; toggle `settings_household_events_toggle` "Member joined & role changes"; toggle `settings_activity_digest_toggle` "Activity digest".
  4. **Weekly summary** — `settings_weekly_summary_section` "Weekly summary"; toggle `settings_weekly_summary_toggle` "Send a weekly summary"; when enabled: day `TextButton` (`settings_weekly_summary_day` "Day: %s", cycling or an `AlertDialog` list of the 7 localized `java.text.DateFormatSymbols().weekdays` — use a simple dialog with 7 `TextButton`s) + time `TextButton` (`settings_weekly_summary_time` "Time: %1$02d:%2$02d") with the shared TimePicker dialog.
  5. **App updates** — `settings_app_updates_section` "App updates"; toggle `settings_app_updates_toggle` "Notify about new versions".
- NL translations for every key (e.g. "Bijna-op-herinnering", "Herinner me dagelijks", "Huishouden", "Lid toegetreden & rolwijzigingen", "Activiteitenoverzicht", "Wekelijkse samenvatting", "Stuur een wekelijkse samenvatting", "Dag: %s", "Tijd: %1$02d:%2$02d", "App-updates", "Meld nieuwe versies").

- [ ] **Step 1: Write failing ViewModel tests** — clone `ReminderViewModelTest` style: `FakeNotificationPrefsStore` (in-memory), `RecordingLowStockReminderScheduler : LowStockReminderScheduler()` overriding `reschedule`; assert setters persist to the store, update the flow, and (low-stock only) reschedule with the mapped `ReminderSettings`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** ViewModels, screen sections, strings (EN+NL). **Step 4: Run** `./gradlew testDebugUnitTest ktlintCheck detekt` → PASS (StringResourceUsageTest confirms every string is wired). Manually install on the Pixel 7 Pro (`./gradlew installDebug`) and eyeball the screen scrolling + dialogs. **Step 5: Commit** — `git commit -m "feat: full notifications settings screen (low stock, household, weekly, app updates)"`.

---

### Task 12: Docs — API contract, spec amendments, CLAUDE.md guardrails

**Files:**
- Modify: `inventory-laravel/docs/specs/api-contract.md` — document `GET /notifications` (params, response shape, 50-row page, cursor semantics), `GET /low-stock/count`, AND the previously-undocumented `GET /missing-items/count` (found missing during exploration).
- Modify: `inventory-laravel/docs/superpowers/specs/2026-07-26-notification-feed-design.md` — record the three v1 amendments: activity digest excludes move batches; low-stock notification has no deep link; weekly summary body is "X missing, Y running low" (no product total).
- Modify: `inventory-laravel/CLAUDE.md` and `inventory-android/CLAUDE.md` — extend the notifications carve-out bullet: the notification feed (`inventory_notifications`) is user-addressed and coarse; the MCP-only activity log remains app-invisible; new channels + defaults listed.

- [ ] **Step 1:** Write all doc updates. **Step 2:** `composer test` (laravel) still green; android untouched. **Step 3:** Commit per repo:
```bash
# in inventory-laravel
git commit -m "docs: notification feed API contract + spec amendments + guardrail update"
# in inventory-android
git commit -m "docs: notification feed guardrail update"
```

---

### Task 13: Full verification gates

- [ ] **Laravel:** `composer test` — full suite green; `vendor/bin/pint --test` clean.
- [ ] **Android:** `./gradlew testDebugUnitTest ktlintCheck detekt` — all green (remember: detekt skips androidTest; MagicNumber applies — hoist any literal hours/ids into named constants).
- [ ] **Device smoke (Pixel 7 Pro):** install; open More → Notifications, flip every toggle, set times; trigger the feed end-to-end: from a second account join the household via code, then run `adb shell cmd jobscheduler` — simpler: temporarily trigger the worker via `adb shell am broadcast` is not possible for WorkManager; instead use `./gradlew installDebug` + in Android Studio's App Inspection or simply set the poll interval — do NOT change code; verify the pieces separately: (a) confirm feed rows land server-side (`GET /notifications` with the user's token via curl), (b) confirm the worker is enqueued: `adb shell dumpsys jobscheduler | grep -A2 inventory` shows the periodic jobs. A true end-to-end poll fires within 6 h on the device.
- [ ] **Commit any stragglers** per repo, same-intent grouping.

---

## Self-review notes (done at planning time)

- Spec coverage: feed table/model (T1), writers all six sites (T2), feed endpoint (T3), low-stock count (T4), DTO/API/repos (T5), prefs+cursor stores (T6), low-stock reminder on@18:00 (T7), digest+weekly logic (T8), poll worker+channels (T9), app-update toggle (T10), settings UI five sections (T11), docs+retention... retention = prune (T1). Deep links: household events open the app plain v1 like app updates — consistent with the low-stock amendment (FeedNotifier uses plain MainActivity intents; the missing-items deep-link extra pattern is there when wanted).
- Deviations from spec are explicit and get written back into the spec (T12).
- Type consistency checked: `ReminderSettings` reuse for low-stock, `FeedEventDto`/`NotificationPrefs` names match across T5/T6/T8/T9/T11.
