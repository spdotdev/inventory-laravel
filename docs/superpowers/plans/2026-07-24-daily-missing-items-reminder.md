# Daily Missing-Items Reminder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the user opt into a once-daily, self-timed notification telling them how many mandatory items are currently missing (quantity 0), reusing the notification-channel/WorkManager infrastructure the app-update-notifications feature already shipped.

**Architecture:** Laravel exposes one new account-wide (not household-scoped) endpoint, `GET /api/v1/missing-items/count`; Android adds a device-local on/off + time preference in Settings, a second independent WorkManager schedule + notification channel, and a minimal `onNewIntent`-based deep link so tapping the notification opens the Missing tab even if the app is already running.

**Tech Stack:** Laravel 13 (PHP), PHPUnit, Sanctum; Kotlin/Compose, Hilt, Retrofit, WorkManager, Material3 `TimePicker`.

## Global Constraints

- Both repos' CLAUDE.md guardrails were updated 2026-07-24 to carve this feature out of the "no reminders" scope cut — this plan is the implementation of that carve-out, not a violation of it.
- Backend: the new route is account-wide, NOT household-scoped — it goes in the existing `Route::middleware('auth:sanctum')->group(...)` block in `routes/api.php`, before the nested `household.member` group (same block `me`/`households.index`/`households.store` already live in).
- Backend: missing = `is_mandatory = true AND quantity = 0`, scoped to every household the authenticated user belongs to (via the `inventory_household_user` pivot) — matches the existing client-side `MissingItem` definition in `HierarchyStore.kt`, not a new definition.
- Android: package root `app/src/main/java/dev/scuttle/inventory/`; `Routes` is a `private object` inside `MainActivity.kt` (no standalone `Routes.kt`); `Routes.MISSING_ITEMS` already exists as `"missing-items?fromDrawer={fromDrawer}"` via `Routes.missingItems(fromDrawer = ...)`.
- Android: `androidx.work:work-runtime-ktx:2.10.0` is already a dependency (added for app-update-notifications) — `ExistingPeriodicWorkPolicy` has `REPLACE`, `KEEP`, `UPDATE`, `CANCEL_AND_REENQUEUE` in this version; use `UPDATE` when the user changes the reminder time (swaps the schedule without losing the existing enqueue), `KEEP` only for the idempotent boot-time enqueue in `InventoryApp.onCreate()`.
- Android: `MainActivity`'s manifest entry is `android:launchMode="singleTop"` with **no existing `onNewIntent` override** — a notification tap while the Activity is already alive is delivered via `onNewIntent`, not a fresh `onCreate`, so this plan must add an `onNewIntent` override (nothing in the codebase does this yet).
- Android: device-local preferences (not synced server-side) reuse the exact same `SharedPreferences("inventory_settings", Context.MODE_PRIVATE)` file `SharedPrefsThemeModeStore` already uses, new key names only.
- Android: JVM unit tests live flat under `app/src/test/java/dev/scuttle/inventory/<Name>Test.kt`, use `kotlinx.coroutines.test.runTest` + the existing `MainDispatcherRule` + hand-written fakes (no mocking library).
- Compose BOM in use: `androidx.compose:compose-bom:2026.06.01` — Material3's `TimePicker`/`rememberTimePickerState` API is available and stable at this version; there is no built-in `TimePickerDialog` composable, so the dialog shell is hand-built the same way `SettingsScreen.kt`'s existing sign-out confirmation `AlertDialog` is.

---

## Part A — Laravel backend (`inventory-laravel`)

### Task 1: `MissingItemsController` + route + test

**Files:**
- Create: `src/Http/Controllers/Api/MissingItemsController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/MissingItemsApiTest.php`

**Interfaces:**
- Produces: `GET /api/v1/missing-items/count` → `{"data": {"count": <int>}}`, `auth:sanctum` protected, account-wide (every household the caller belongs to).

- [ ] **Step 1: Write the controller**

```php
<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spdotdev\Inventory\Models\Product;

class MissingItemsController
{
    public function count(Request $request): JsonResponse
    {
        $count = Product::query()
            ->where('is_mandatory', true)
            ->where('quantity', 0)
            ->whereHas('shelf.location.household.users', function ($query) use ($request) {
                $query->where('inventory_users.id', $request->user()->id);
            })
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }
}
```

- [ ] **Step 2: Register the route** — add to `routes/api.php`, inside the existing `Route::middleware('auth:sanctum')->group(function () { ... })` block, right after the `households/join` route and before the nested `Route::middleware('household.member')->scopeBindings()->group(...)` block:

```php
Route::get('missing-items/count', [MissingItemsController::class, 'count'])->name('inventory.api.missing-items.count');
```

Add `use Spdotdev\Inventory\Http\Controllers\Api\MissingItemsController;` to the top of `routes/api.php` alongside the other controller `use` statements (alphabetically, between `MemberController` and `ProductController`).

- [ ] **Step 3: Write the feature test**

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class MissingItemsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function makeShelf(Household $household): Shelf
    {
        $location = StorageLocation::query()->create([
            'household_id' => $household->id,
            'name' => 'Fridge',
            'type' => 'fridge',
        ]);

        return Shelf::query()->create([
            'location_id' => $location->id,
            'name' => 'Top shelf',
        ]);
    }

    public function test_count_reflects_missing_items_across_all_the_users_households(): void
    {
        $user = User::query()->create([
            'name' => 'Stan',
            'email' => 'stan@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $householdA = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $householdA->users()->attach($user);
        $shelfA = $this->makeShelf($householdA);
        Product::query()->create([
            'shelf_id' => $shelfA->id,
            'name' => 'Milk',
            'is_mandatory' => true,
            'quantity' => 0,
        ]);
        // Not mandatory — must not count.
        Product::query()->create([
            'shelf_id' => $shelfA->id,
            'name' => 'Snacks',
            'is_mandatory' => false,
            'quantity' => 0,
        ]);
        // Mandatory but in stock — must not count.
        Product::query()->create([
            'shelf_id' => $shelfA->id,
            'name' => 'Bread',
            'is_mandatory' => true,
            'quantity' => 3,
        ]);

        $householdB = Household::query()->create(['name' => 'Cabin', 'join_code' => 'BBBB2222']);
        $householdB->users()->attach($user);
        $shelfB = $this->makeShelf($householdB);
        Product::query()->create([
            'shelf_id' => $shelfB->id,
            'name' => 'Eggs',
            'is_mandatory' => true,
            'quantity' => 0,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/missing-items/count")
            ->assertOk()
            ->assertJsonPath('data.count', 2);
    }

    public function test_count_excludes_other_users_households(): void
    {
        $user = User::query()->create([
            'name' => 'Stan',
            'email' => 'stan@example.test',
            'password' => bcrypt('secret123'),
        ]);
        $otherUser = User::query()->create([
            'name' => 'Alex',
            'email' => 'alex@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $otherHousehold = Household::query()->create(['name' => 'Not Mine', 'join_code' => 'CCCC3333']);
        $otherHousehold->users()->attach($otherUser);
        $shelf = $this->makeShelf($otherHousehold);
        Product::query()->create([
            'shelf_id' => $shelf->id,
            'name' => 'Milk',
            'is_mandatory' => true,
            'quantity' => 0,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/missing-items/count")
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_count_requires_authentication(): void
    {
        $this->getJson("{$this->base}/missing-items/count")->assertStatus(401);
    }
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit --filter MissingItemsApiTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the full suite, Pint, and Larastan**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=512M`
Expected: all PASS, no regressions.

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/Api/MissingItemsController.php routes/api.php tests/Feature/MissingItemsApiTest.php
git commit -m "feat: add account-wide missing-items count endpoint"
```

---

## Part B — Android client (`inventory-android`)

### Task 2: `MissingItemsApi` + `MissingItemsRepository`

**Files:**
- Create: `app/src/main/java/dev/scuttle/inventory/data/dto/MissingItemsCountResponse.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/api/MissingItemsApi.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/missingitems/MissingItemsRepository.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/missingitems/MissingItemsRepositoryImpl.kt`
- Modify: `app/src/main/java/dev/scuttle/inventory/di/NetworkModule.kt`
- Modify: `app/src/main/java/dev/scuttle/inventory/di/RepositoryModule.kt`
- Test: `app/src/test/java/dev/scuttle/inventory/MissingItemsRepositoryTest.kt`

**Interfaces:**
- Produces: `MissingItemsRepository.count(): Int?` — returns `null` on any failure (network error, non-200), never throws. Callers treat `null` the same as "0 / skip" per the spec's error-handling posture.

- [ ] **Step 1: Write the DTO**

```kotlin
package dev.scuttle.inventory.data.dto

import kotlinx.serialization.Serializable

@Serializable
data class MissingItemsCountResponse(
    val data: MissingItemsCountData,
)

@Serializable
data class MissingItemsCountData(
    val count: Int,
)
```

- [ ] **Step 2: Write the Api interface**

```kotlin
package dev.scuttle.inventory.data.api

import dev.scuttle.inventory.data.dto.MissingItemsCountResponse
import retrofit2.http.GET

interface MissingItemsApi {
    @GET("missing-items/count")
    suspend fun count(): MissingItemsCountResponse
}
```

- [ ] **Step 3: Add the provider to `NetworkModule.kt`**, alongside the other `provide*Api` functions:

```kotlin
@Provides
@Singleton
fun provideMissingItemsApi(retrofit: Retrofit): MissingItemsApi = retrofit.create(MissingItemsApi::class.java)
```

(Add `import dev.scuttle.inventory.data.api.MissingItemsApi` to the file's imports.) This endpoint requires the caller's bearer token (unlike the public app-version endpoint) — the shared `Retrofit`/`OkHttpClient`'s existing `AuthInterceptor` already attaches it to every request, so no special handling is needed here.

- [ ] **Step 4: Write `MissingItemsRepository` interface + impl**

```kotlin
package dev.scuttle.inventory.data.missingitems

interface MissingItemsRepository {
    suspend fun count(): Int?
}
```

```kotlin
package dev.scuttle.inventory.data.missingitems

import android.util.Log
import dev.scuttle.inventory.data.api.MissingItemsApi
import javax.inject.Inject

class MissingItemsRepositoryImpl
    @Inject
    constructor(
        private val api: MissingItemsApi,
    ) : MissingItemsRepository {
        override suspend fun count(): Int? =
            try {
                api.count().data.count
            } catch (e: Exception) {
                Log.w("MissingItemsRepository", "Missing-items count check failed", e)
                null
            }
    }
```

- [ ] **Step 5: Write the test**

```kotlin
package dev.scuttle.inventory

import dev.scuttle.inventory.data.api.MissingItemsApi
import dev.scuttle.inventory.data.dto.MissingItemsCountData
import dev.scuttle.inventory.data.dto.MissingItemsCountResponse
import dev.scuttle.inventory.data.missingitems.MissingItemsRepositoryImpl
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Rule
import org.junit.Test

class MissingItemsRepositoryTest {
    @get:Rule
    val mainDispatcherRule = MainDispatcherRule()

    private class FakeMissingItemsApi(
        private val count: Int = 0,
        private val throwOnCall: Boolean = false,
    ) : MissingItemsApi {
        override suspend fun count(): MissingItemsCountResponse {
            if (throwOnCall) throw RuntimeException("offline")
            return MissingItemsCountResponse(data = MissingItemsCountData(count = count))
        }
    }

    @Test
    fun count_returns_the_real_count_on_success() =
        runTest {
            val repository = MissingItemsRepositoryImpl(FakeMissingItemsApi(count = 3))

            assertEquals(3, repository.count())
        }

    @Test
    fun count_returns_null_when_the_api_call_fails() =
        runTest {
            val repository = MissingItemsRepositoryImpl(FakeMissingItemsApi(throwOnCall = true))

            assertNull(repository.count())
        }
}
```

- [ ] **Step 6: Add the Hilt `@Binds`** to `RepositoryModule.kt`, following the exact same pattern as `bindAppUpdateRepository`:

```kotlin
@Binds
@Singleton
abstract fun bindMissingItemsRepository(impl: MissingItemsRepositoryImpl): MissingItemsRepository
```

- [ ] **Step 7: Run the tests**

Run: `./gradlew :app:testDebugUnitTest --tests "dev.scuttle.inventory.MissingItemsRepositoryTest"`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/data/dto/MissingItemsCountResponse.kt \
        app/src/main/java/dev/scuttle/inventory/data/api/MissingItemsApi.kt \
        app/src/main/java/dev/scuttle/inventory/data/missingitems/ \
        app/src/main/java/dev/scuttle/inventory/di/NetworkModule.kt \
        app/src/main/java/dev/scuttle/inventory/di/RepositoryModule.kt \
        app/src/test/java/dev/scuttle/inventory/MissingItemsRepositoryTest.kt
git commit -m "feat: add MissingItemsRepository backed by the new count endpoint"
```

---

### Task 3: `ReminderSettingsStore` + `ReminderScheduler`

**Files:**
- Create: `app/src/main/java/dev/scuttle/inventory/data/settings/ReminderSettings.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/settings/ReminderSettingsStore.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/settings/SharedPrefsReminderSettingsStore.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/work/ReminderScheduler.kt`
- Modify: `app/src/main/java/dev/scuttle/inventory/di/RepositoryModule.kt` (or wherever `ThemeModeStore` is bound — see Step 6)
- Test: `app/src/test/java/dev/scuttle/inventory/ReminderSchedulerTest.kt`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `data class ReminderSettings(val enabled: Boolean = false, val hour: Int = 9, val minute: Int = 0)`; `ReminderSettingsStore.get(): ReminderSettings`, `.set(settings: ReminderSettings)`; `ReminderScheduler.reschedule(context: Context, settings: ReminderSettings)` — Task 4/5 depend on this exact signature.

- [ ] **Step 1: Write `ReminderSettings`**

```kotlin
package dev.scuttle.inventory.data.settings

data class ReminderSettings(
    val enabled: Boolean = false,
    val hour: Int = 9,
    val minute: Int = 0,
)
```

- [ ] **Step 2: Write `ReminderSettingsStore`**

```kotlin
package dev.scuttle.inventory.data.settings

interface ReminderSettingsStore {
    fun get(): ReminderSettings

    fun set(settings: ReminderSettings)
}
```

- [ ] **Step 3: Write `SharedPrefsReminderSettingsStore`**

```kotlin
package dev.scuttle.inventory.data.settings

import android.content.Context

class SharedPrefsReminderSettingsStore(
    context: Context,
) : ReminderSettingsStore {
    private val prefs = context.getSharedPreferences("inventory_settings", Context.MODE_PRIVATE)

    override fun get(): ReminderSettings =
        ReminderSettings(
            enabled = prefs.getBoolean(KEY_ENABLED, false),
            hour = prefs.getInt(KEY_HOUR, 9),
            minute = prefs.getInt(KEY_MINUTE, 0),
        )

    override fun set(settings: ReminderSettings) {
        prefs.edit()
            .putBoolean(KEY_ENABLED, settings.enabled)
            .putInt(KEY_HOUR, settings.hour)
            .putInt(KEY_MINUTE, settings.minute)
            .apply()
    }

    private companion object {
        const val KEY_ENABLED = "missing_items_reminder_enabled"
        const val KEY_HOUR = "missing_items_reminder_hour"
        const val KEY_MINUTE = "missing_items_reminder_minute"
    }
}
```

- [ ] **Step 4: Write `ReminderScheduler`**

```kotlin
package dev.scuttle.inventory.work

import android.content.Context
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import dev.scuttle.inventory.data.settings.ReminderSettings
import java.util.Calendar
import java.util.concurrent.TimeUnit
import javax.inject.Inject

private const val MISSING_ITEMS_CHECK_WORK_NAME = "missing_items_check"
private const val REMINDER_INTERVAL_HOURS = 24L

// Open (not the Kotlin default final) so ReminderViewModelTest (Task 6) can
// subclass it with a recording fake instead of touching a real WorkManager,
// which doesn't exist in a plain JVM unit test.
open class ReminderScheduler
    @Inject
    constructor() {
        open fun reschedule(
            context: Context,
            settings: ReminderSettings,
        ) {
            val workManager = WorkManager.getInstance(context)

            if (!settings.enabled) {
                workManager.cancelUniqueWork(MISSING_ITEMS_CHECK_WORK_NAME)
                return
            }

            val delayMillis = initialDelayMillis(settings.hour, settings.minute)
            val request =
                PeriodicWorkRequestBuilder<MissingItemsCheckWorker>(REMINDER_INTERVAL_HOURS, TimeUnit.HOURS)
                    .setInitialDelay(delayMillis, TimeUnit.MILLISECONDS)
                    .build()

            workManager.enqueueUniquePeriodicWork(
                MISSING_ITEMS_CHECK_WORK_NAME,
                ExistingPeriodicWorkPolicy.UPDATE,
                request,
            )
        }

        /**
         * Enqueues idempotently at app boot without disturbing an already-scheduled
         * reminder's timing (unlike [reschedule]'s UPDATE policy, used when the user
         * actively changes the time).
         */
        open fun ensureScheduled(
            context: Context,
            settings: ReminderSettings,
        ) {
            if (!settings.enabled) return

            val delayMillis = initialDelayMillis(settings.hour, settings.minute)
            val request =
                PeriodicWorkRequestBuilder<MissingItemsCheckWorker>(REMINDER_INTERVAL_HOURS, TimeUnit.HOURS)
                    .setInitialDelay(delayMillis, TimeUnit.MILLISECONDS)
                    .build()

            WorkManager.getInstance(context)
                .enqueueUniquePeriodicWork(
                    MISSING_ITEMS_CHECK_WORK_NAME,
                    ExistingPeriodicWorkPolicy.KEEP,
                    request,
                )
        }

        internal fun initialDelayMillis(
            hour: Int,
            minute: Int,
            now: Calendar = Calendar.getInstance(),
        ): Long {
            val target = now.clone() as Calendar
            target.set(Calendar.HOUR_OF_DAY, hour)
            target.set(Calendar.MINUTE, minute)
            target.set(Calendar.SECOND, 0)
            target.set(Calendar.MILLISECOND, 0)

            if (target.timeInMillis <= now.timeInMillis) {
                target.add(Calendar.DAY_OF_YEAR, 1)
            }

            return target.timeInMillis - now.timeInMillis
        }
    }
```

- [ ] **Step 5: Write `ReminderSchedulerTest`** — tests the pure `initialDelayMillis` function directly, without touching a real `WorkManager` (JVM tests have no Android runtime to schedule jobs against):

```kotlin
package dev.scuttle.inventory

import dev.scuttle.inventory.work.ReminderScheduler
import org.junit.Assert.assertEquals
import org.junit.Test
import java.util.Calendar

class ReminderSchedulerTest {
    private val scheduler = ReminderScheduler()

    private fun calendarAt(
        hour: Int,
        minute: Int,
    ): Calendar =
        Calendar.getInstance().apply {
            set(Calendar.HOUR_OF_DAY, hour)
            set(Calendar.MINUTE, minute)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
        }

    @Test
    fun schedules_later_today_when_the_target_time_has_not_passed_yet() {
        val now = calendarAt(hour = 8, minute = 0)

        val delay = scheduler.initialDelayMillis(hour = 9, minute = 0, now = now)

        assertEquals(60 * 60 * 1000L, delay)
    }

    @Test
    fun schedules_tomorrow_when_the_target_time_has_already_passed_today() {
        val now = calendarAt(hour = 10, minute = 0)

        val delay = scheduler.initialDelayMillis(hour = 9, minute = 0, now = now)

        assertEquals(23 * 60 * 60 * 1000L, delay)
    }

    @Test
    fun schedules_tomorrow_when_the_target_time_is_exactly_now() {
        val now = calendarAt(hour = 9, minute = 0)

        val delay = scheduler.initialDelayMillis(hour = 9, minute = 0, now = now)

        assertEquals(24 * 60 * 60 * 1000L, delay)
    }
}
```

- [ ] **Step 6: Run the test**

Run: `./gradlew :app:testDebugUnitTest --tests "dev.scuttle.inventory.ReminderSchedulerTest"`
Expected: PASS (3 tests).

- [ ] **Step 7: Add the Hilt `@Binds` for `ReminderSettingsStore`** — find the module that already provides `ThemeModeStore` (search for `SharedPrefsThemeModeStore` in `di/`) and add an equivalent `@Provides` (this store needs a `Context`, constructed the same way `ThemeModeStore`'s provider already is — copy that exact provider function's shape, swapping the class names):

```kotlin
@Provides
@Singleton
fun provideReminderSettingsStore(
    @ApplicationContext context: Context,
): ReminderSettingsStore = SharedPrefsReminderSettingsStore(context)
```

(Match whatever module/annotations `ThemeModeStore`'s existing provider uses exactly — same `@Module`, same `@InstallIn` scope.)

- [ ] **Step 8: Build to confirm Hilt wiring compiles**

Run: `./gradlew :app:assembleDebug`
Expected: BUILD SUCCESSFUL.

- [ ] **Step 9: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/data/settings/ReminderSettings.kt \
        app/src/main/java/dev/scuttle/inventory/data/settings/ReminderSettingsStore.kt \
        app/src/main/java/dev/scuttle/inventory/data/settings/SharedPrefsReminderSettingsStore.kt \
        app/src/main/java/dev/scuttle/inventory/work/ReminderScheduler.kt \
        app/src/test/java/dev/scuttle/inventory/ReminderSchedulerTest.kt \
        app/src/main/java/dev/scuttle/inventory/di/
git commit -m "feat: add reminder settings store and WorkManager scheduler"
```

---

### Task 4: `MissingItemsNotifier` + `MissingItemsCheckWorker` + channel wiring

**Files:**
- Create: `app/src/main/java/dev/scuttle/inventory/work/MissingItemsNotifier.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/work/MissingItemsCheckWorker.kt`
- Modify: `app/src/main/java/dev/scuttle/inventory/InventoryApp.kt`
- Modify: `app/src/main/res/values/strings.xml` + `app/src/main/res/values-nl/strings.xml`

**Interfaces:**
- Consumes: `MissingItemsRepository.count()` (Task 2), `ReminderSettingsStore` + `ReminderScheduler.ensureScheduled()` (Task 3).
- Produces: notification channel id `"missing_items_reminder"`; `MissingItemsCheckWorker` (a `@HiltWorker`) posting to it.

- [ ] **Step 1: Write `MissingItemsNotifier`**, following `AppUpdateNotifier.kt`'s exact structure (channel creation + posting):

```kotlin
package dev.scuttle.inventory.work

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.app.PendingIntent
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import dev.scuttle.inventory.MainActivity
import dev.scuttle.inventory.R

private const val CHANNEL_ID = "missing_items_reminder"
private const val NOTIFICATION_ID = 1002
internal const val NAVIGATE_TO_MISSING_ITEMS = "missing_items"

fun createMissingItemsNotificationChannel(context: Context) {
    val channel =
        NotificationChannel(
            CHANNEL_ID,
            context.getString(R.string.notification_channel_missing_items_name),
            NotificationManager.IMPORTANCE_DEFAULT,
        ).apply {
            description = context.getString(R.string.notification_channel_missing_items_description)
        }
    val manager = context.getSystemService(NotificationManager::class.java)
    manager.createNotificationChannel(channel)
}

fun postMissingItemsNotification(
    context: Context,
    count: Int,
) {
    if (count <= 0) return

    val title = context.resources.getQuantityString(R.plurals.notification_missing_items_title, count, count)

    val intent =
        Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra(MainActivity.EXTRA_NAVIGATE_TO, NAVIGATE_TO_MISSING_ITEMS)
        }
    val pendingIntent =
        PendingIntent.getActivity(
            context,
            0,
            intent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )

    val notification =
        NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()

    if (ActivityCompat.checkSelfPermission(
            context,
            android.Manifest.permission.POST_NOTIFICATIONS,
        ) != PackageManager.PERMISSION_GRANTED
    ) {
        return
    }
    context.getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notification)
}
```

Add the string/plurals resources to `strings.xml` and `values-nl/strings.xml`:

```xml
<string name="notification_channel_missing_items_name">Missing items reminder</string>
<string name="notification_channel_missing_items_description">Reminds you once a day if any mandatory items are missing</string>
<plurals name="notification_missing_items_title">
    <item quantity="one">%d item is missing</item>
    <item quantity="other">%d items are missing</item>
</plurals>
```

Dutch (`values-nl/strings.xml`):

```xml
<string name="notification_channel_missing_items_name">Herinnering ontbrekende items</string>
<string name="notification_channel_missing_items_description">Herinnert je eenmaal per dag als er verplichte items ontbreken</string>
<plurals name="notification_missing_items_title">
    <item quantity="one">%d item ontbreekt</item>
    <item quantity="other">%d items ontbreken</item>
</plurals>
```

- [ ] **Step 2: Write `MissingItemsCheckWorker`**

```kotlin
package dev.scuttle.inventory.work

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject
import dev.scuttle.inventory.data.missingitems.MissingItemsRepository

@HiltWorker
class MissingItemsCheckWorker
    @AssistedInject
    constructor(
        @Assisted appContext: Context,
        @Assisted workerParams: WorkerParameters,
        private val repository: MissingItemsRepository,
    ) : CoroutineWorker(appContext, workerParams) {
        override suspend fun doWork(): Result {
            val count = repository.count() ?: return Result.success()
            postMissingItemsNotification(applicationContext, count)
            return Result.success()
        }
    }
```

- [ ] **Step 3: Wire `InventoryApp.kt`** — create the channel and idempotently ensure the reminder is scheduled at boot (only if the user has it enabled):

```kotlin
package dev.scuttle.inventory

import android.app.Application
import androidx.hilt.work.HiltWorkerFactory
import androidx.work.Configuration
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import dagger.hilt.android.HiltAndroidApp
import dev.scuttle.inventory.data.settings.ReminderSettingsStore
import dev.scuttle.inventory.work.AppUpdateCheckWorker
import dev.scuttle.inventory.work.MissingItemsCheckWorker
import dev.scuttle.inventory.work.ReminderScheduler
import dev.scuttle.inventory.work.createAppUpdatesNotificationChannel
import dev.scuttle.inventory.work.createMissingItemsNotificationChannel
import java.util.concurrent.TimeUnit
import javax.inject.Inject

private const val APP_UPDATE_CHECK_INTERVAL_HOURS = 24L

@HiltAndroidApp
class InventoryApp :
    Application(),
    Configuration.Provider {
    @Inject
    lateinit var workerFactory: HiltWorkerFactory

    @Inject
    lateinit var reminderSettingsStore: ReminderSettingsStore

    @Inject
    lateinit var reminderScheduler: ReminderScheduler

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder().setWorkerFactory(workerFactory).build()

    override fun onCreate() {
        super.onCreate()
        createAppUpdatesNotificationChannel(this)
        createMissingItemsNotificationChannel(this)
        scheduleAppUpdateCheck()
        reminderScheduler.ensureScheduled(this, reminderSettingsStore.get())
    }

    private fun scheduleAppUpdateCheck() {
        val request =
            PeriodicWorkRequestBuilder<AppUpdateCheckWorker>(APP_UPDATE_CHECK_INTERVAL_HOURS, TimeUnit.HOURS)
                .build()
        WorkManager
            .getInstance(this)
            .enqueueUniquePeriodicWork(
                "app_update_check",
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
    }
}
```

(`MissingItemsCheckWorker` import is unused directly in this file but required for Hilt's generated worker-factory aggregation to see the class — keep it only if the build fails without it; Hilt typically discovers `@HiltWorker` classes via annotation processing regardless of imports in `InventoryApp.kt`, so omit this specific import if unused-import lint flags it.)

- [ ] **Step 4: Build and run the JVM test suite**

Run: `./gradlew :app:assembleDebug`
Expected: BUILD SUCCESSFUL.

Run: `./gradlew :app:testDebugUnitTest`
Expected: PASS, no regressions (including `StringResourceUsageTest` — the new strings/plurals are referenced by `MissingItemsNotifier.kt`, satisfying it).

- [ ] **Step 5: Run ktlint/detekt**

Run: `./gradlew ktlintCheck detekt`
Expected: PASS. Fix any formatting/violations before committing (this repo's convention — never regenerate the baseline for new code, only fix it).

- [ ] **Step 6: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/work/MissingItemsNotifier.kt \
        app/src/main/java/dev/scuttle/inventory/work/MissingItemsCheckWorker.kt \
        app/src/main/java/dev/scuttle/inventory/InventoryApp.kt \
        app/src/main/res/values/strings.xml app/src/main/res/values-nl/strings.xml
git commit -m "feat: post a daily missing-items notification via WorkManager"
```

---

### Task 5: Deep link from notification tap (`MainActivity` `onNewIntent`)

**Files:**
- Modify: `app/src/main/java/dev/scuttle/inventory/MainActivity.kt`

**Interfaces:**
- Consumes: `MissingItemsNotifier.NAVIGATE_TO_MISSING_ITEMS` constant (Task 4) via `MainActivity.EXTRA_NAVIGATE_TO`.
- Produces: `MainActivity.EXTRA_NAVIGATE_TO: String` (public const, so `MissingItemsNotifier` can reference it — Task 4's code above already assumes this exists; this task creates it).

- [ ] **Step 1: Add the `EXTRA_NAVIGATE_TO` constant and an `onNewIntent` override**, plus a way for the Compose tree to observe a pending navigation target. Add near the top of the `MainActivity` class body (alongside its existing `@Inject lateinit var liveUpdates: LiveUpdates`):

```kotlin
companion object {
    const val EXTRA_NAVIGATE_TO = "navigate_to"
}

private val pendingNavigation = MutableStateFlow<String?>(null)

override fun onNewIntent(intent: Intent) {
    super.onNewIntent(intent)
    setIntent(intent)
    intent.getStringExtra(EXTRA_NAVIGATE_TO)?.let { pendingNavigation.value = it }
}
```

Add `import kotlinx.coroutines.flow.MutableStateFlow` to the file's imports if not already present.

- [ ] **Step 2: Read any launch-time extra too**, and collect `pendingNavigation` inside `setContent { }` to actually navigate. Modify `onCreate` so the existing extra-check also runs once at cold start (the notification could also cold-start the app, not just resume it), and add a `LaunchedEffect` that reacts whenever `pendingNavigation` changes:

```kotlin
override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    enableEdgeToEdge()
    liveUpdates.start()
    intent.getStringExtra(EXTRA_NAVIGATE_TO)?.let { pendingNavigation.value = it }
    setContent {
        // ... existing themeViewModel/mode/dark setup unchanged ...
        val navController = rememberNavController()
        val navigateTo by pendingNavigation.collectAsState()

        LaunchedEffect(navigateTo) {
            if (navigateTo == dev.scuttle.inventory.work.NAVIGATE_TO_MISSING_ITEMS) {
                navController.navigate(Routes.missingItems(fromDrawer = true)) { launchSingleTop = true }
                pendingNavigation.value = null
            }
        }

        InventoryTheme(darkTheme = dark) {
            Surface(modifier = Modifier.fillMaxSize()) {
                InventoryNavHost(themeViewModel = themeViewModel, navController = navController)
                // ... existing update-dialog block unchanged ...
            }
        }
    }
}
```

This step needs judgment about the exact existing `navController`/`InventoryNavHost` call shape in the current file (the file already creates a `NavController` somewhere inside `InventoryNavHost` — check whether `InventoryNavHost` currently creates its own internal `rememberNavController()` or accepts one as a parameter). If `InventoryNavHost` currently owns its `NavController` internally rather than accepting one, thread a `navController: NavHostController` parameter through `InventoryNavHost`'s signature so `MainActivity` can drive navigation into it from this `LaunchedEffect` — this is the one part of this task requiring you to read the current `InventoryNavHost` signature in `MainActivity.kt` before editing, since the plan can't assume which shape it's currently in without risking a wrong diff.

- [ ] **Step 3: Build and manually verify**

Run: `./gradlew :app:assembleDebug`
Expected: BUILD SUCCESSFUL.

There is no automated test for this step (it's Activity-lifecycle/Intent wiring, not unit-testable without an instrumented test, which this repo's convention avoids for this class of feature — see the app-update-notifications plan's identical rationale). Manual verification happens in this plan's final device-verification checklist.

- [ ] **Step 4: Run ktlint/detekt**

Run: `./gradlew ktlintCheck detekt`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/MainActivity.kt
git commit -m "feat: deep-link missing-items notification taps to the Missing tab"
```

---

### Task 6: Settings UI (toggle + time picker) + `ReminderViewModel`

**Files:**
- Create: `app/src/main/java/dev/scuttle/inventory/ui/settings/ReminderViewModel.kt`
- Modify: `app/src/main/java/dev/scuttle/inventory/ui/settings/SettingsScreen.kt`
- Modify: `app/src/main/res/values/strings.xml` + `app/src/main/res/values-nl/strings.xml`
- Test: `app/src/test/java/dev/scuttle/inventory/ReminderViewModelTest.kt`

**Interfaces:**
- Consumes: `ReminderSettingsStore` (Task 3), `ReminderScheduler.reschedule()` (Task 3).
- Produces: `ReminderViewModel.settings: StateFlow<ReminderSettings>`, `.setEnabled(enabled: Boolean)`, `.setTime(hour: Int, minute: Int)`.

- [ ] **Step 1: Write `ReminderViewModel`**, mirroring `ThemeViewModel`'s exact shape:

```kotlin
package dev.scuttle.inventory.ui.settings

import android.content.Context
import androidx.lifecycle.ViewModel
import dagger.hilt.android.lifecycle.HiltViewModel
import dagger.hilt.android.qualifiers.ApplicationContext
import dev.scuttle.inventory.data.settings.ReminderSettings
import dev.scuttle.inventory.data.settings.ReminderSettingsStore
import dev.scuttle.inventory.work.ReminderScheduler
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject

@HiltViewModel
class ReminderViewModel
    @Inject
    constructor(
        @ApplicationContext private val context: Context,
        private val store: ReminderSettingsStore,
        private val scheduler: ReminderScheduler,
    ) : ViewModel() {
        private val _settings = MutableStateFlow(store.get())
        val settings: StateFlow<ReminderSettings> = _settings.asStateFlow()

        fun setEnabled(enabled: Boolean) {
            val updated = _settings.value.copy(enabled = enabled)
            store.set(updated)
            _settings.value = updated
            scheduler.reschedule(context, updated)
        }

        fun setTime(
            hour: Int,
            minute: Int,
        ) {
            val updated = _settings.value.copy(hour = hour, minute = minute)
            store.set(updated)
            _settings.value = updated
            scheduler.reschedule(context, updated)
        }
    }
```

- [ ] **Step 2: Write `ReminderViewModelTest`**

```kotlin
package dev.scuttle.inventory

import android.content.ContextWrapper
import dev.scuttle.inventory.data.settings.ReminderSettings
import dev.scuttle.inventory.data.settings.ReminderSettingsStore
import dev.scuttle.inventory.ui.settings.ReminderViewModel
import dev.scuttle.inventory.work.ReminderScheduler
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import android.content.Context

class ReminderViewModelTest {
    private class FakeReminderSettingsStore(
        initial: ReminderSettings = ReminderSettings(),
    ) : ReminderSettingsStore {
        var stored = initial
        override fun get(): ReminderSettings = stored

        override fun set(settings: ReminderSettings) {
            stored = settings
        }
    }

    // ReminderScheduler is opened (see Task 3) specifically so this test can
    // override reschedule() to record its argument instead of touching a real
    // WorkManager, which doesn't exist in a plain JVM unit test.
    private class RecordingReminderScheduler : ReminderScheduler() {
        var lastRescheduledWith: ReminderSettings? = null

        override fun reschedule(
            context: Context,
            settings: ReminderSettings,
        ) {
            lastRescheduledWith = settings
        }
    }

    // A bare ContextWrapper(null) is never actually invoked: RecordingReminderScheduler
    // overrides reschedule() to ignore its context argument entirely, so this only
    // needs to type-check as a Context, not behave like one. Avoids introducing a
    // mocking library into a codebase that otherwise hand-writes every fake.
    private fun fakeContext(): Context = ContextWrapper(null)

    @Test
    fun setEnabled_persists_and_reschedules() {
        val store = FakeReminderSettingsStore()
        val scheduler = RecordingReminderScheduler()
        val viewModel = ReminderViewModel(context = fakeContext(), store = store, scheduler = scheduler)

        viewModel.setEnabled(true)

        assertTrue(store.stored.enabled)
        assertEquals(true, scheduler.lastRescheduledWith?.enabled)
    }

    @Test
    fun setTime_persists_and_reschedules() {
        val store = FakeReminderSettingsStore()
        val scheduler = RecordingReminderScheduler()
        val viewModel = ReminderViewModel(context = fakeContext(), store = store, scheduler = scheduler)

        viewModel.setTime(hour = 18, minute = 30)

        assertEquals(18, store.stored.hour)
        assertEquals(30, store.stored.minute)
        assertEquals(18, scheduler.lastRescheduledWith?.hour)
    }
}
```

This test needs `ReminderScheduler` to be an open class (not `final`) so `RecordingReminderScheduler` can extend and override `reschedule` — Kotlin classes are final by default. Go back to Task 3's `ReminderScheduler` and mark the class `open` and its `reschedule`/`ensureScheduled` methods `open fun` before writing this test.

- [ ] **Step 3: Run the test**

Run: `./gradlew :app:testDebugUnitTest --tests "dev.scuttle.inventory.ReminderViewModelTest"`
Expected: PASS (2 tests).

- [ ] **Step 4: Add the Settings UI section** — modify `SettingsScreen.kt`, inserting a new section between the existing Theme section and the Join-household section, following the file's existing `Text` section-header pattern:

```kotlin
val reminderViewModel: ReminderViewModel = hiltViewModel()
val reminderSettings by reminderViewModel.settings.collectAsState()
var showTimePicker by remember { mutableStateOf(false) }

Text(
    text = stringResource(R.string.settings_missing_items_reminder_section),
    style = MaterialTheme.typography.titleMedium,
    modifier = Modifier.semantics { heading() },
)
Row(
    verticalAlignment = Alignment.CenterVertically,
    horizontalArrangement = Arrangement.SpaceBetween,
    modifier = Modifier.fillMaxWidth(),
) {
    Text(stringResource(R.string.settings_missing_items_reminder_toggle))
    Switch(
        checked = reminderSettings.enabled,
        onCheckedChange = { reminderViewModel.setEnabled(it) },
    )
}
if (reminderSettings.enabled) {
    TextButton(onClick = { showTimePicker = true }) {
        Text(
            stringResource(
                R.string.settings_missing_items_reminder_time,
                reminderSettings.hour,
                reminderSettings.minute,
            ),
        )
    }
}

if (showTimePicker) {
    val timePickerState =
        rememberTimePickerState(
            initialHour = reminderSettings.hour,
            initialMinute = reminderSettings.minute,
            is24Hour = true,
        )
    AlertDialog(
        onDismissRequest = { showTimePicker = false },
        confirmButton = {
            TextButton(onClick = {
                reminderViewModel.setTime(timePickerState.hour, timePickerState.minute)
                showTimePicker = false
            }) {
                Text(stringResource(R.string.settings_missing_items_reminder_time_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = { showTimePicker = false }) {
                Text(stringResource(R.string.settings_missing_items_reminder_time_cancel))
            }
        },
        text = { TimePicker(state = timePickerState) },
    )
}
```

Add the required imports to `SettingsScreen.kt`: `androidx.compose.material3.Switch`, `androidx.compose.material3.TimePicker`, `androidx.compose.material3.rememberTimePickerState`, `androidx.compose.runtime.mutableStateOf`, `androidx.compose.runtime.remember`, `androidx.compose.runtime.setValue`, `androidx.compose.runtime.getValue`, `dev.scuttle.inventory.ui.settings.ReminderViewModel` (same-package, likely already implicit).

Add the string resources to both `strings.xml` and `values-nl/strings.xml`:

```xml
<string name="settings_missing_items_reminder_section">Missing items reminder</string>
<string name="settings_missing_items_reminder_toggle">Remind me daily</string>
<string name="settings_missing_items_reminder_time">Reminder time: %1$02d:%2$02d</string>
<string name="settings_missing_items_reminder_time_confirm">Save</string>
<string name="settings_missing_items_reminder_time_cancel">Cancel</string>
```

Dutch:

```xml
<string name="settings_missing_items_reminder_section">Herinnering ontbrekende items</string>
<string name="settings_missing_items_reminder_toggle">Dagelijks herinneren</string>
<string name="settings_missing_items_reminder_time">Herinneringstijd: %1$02d:%2$02d</string>
<string name="settings_missing_items_reminder_time_confirm">Opslaan</string>
<string name="settings_missing_items_reminder_time_cancel">Annuleren</string>
```

- [ ] **Step 5: Build and run the full JVM test suite**

Run: `./gradlew :app:assembleDebug`
Expected: BUILD SUCCESSFUL.

Run: `./gradlew :app:testDebugUnitTest`
Expected: PASS, no regressions, `StringResourceUsageTest` satisfied by the new strings actually being referenced in `SettingsScreen.kt`.

- [ ] **Step 6: Run ktlint/detekt**

Run: `./gradlew ktlintCheck detekt`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/ui/settings/ReminderViewModel.kt \
        app/src/main/java/dev/scuttle/inventory/ui/settings/SettingsScreen.kt \
        app/src/test/java/dev/scuttle/inventory/ReminderViewModelTest.kt \
        app/src/main/res/values/strings.xml app/src/main/res/values-nl/strings.xml \
        app/src/main/java/dev/scuttle/inventory/work/ReminderScheduler.kt
git commit -m "feat: add missing-items reminder toggle and time picker to Settings"
```

---

## Manual verification (post-implementation, not automatable)

1. Enable the reminder in Settings with a time a few minutes in the future; confirm the app doesn't crash and the chosen time displays correctly (12/24h formatting).
2. Force the WorkManager job to run immediately for testing (same technique as the app-update-notifications feature): background the app, find the job id via `adb shell dumpsys jobscheduler | grep -B8 MissingItemsCheckWorker`, then `adb shell cmd jobscheduler run -f dev.scuttle.inventory <jobId>`. With at least one mandatory/qty-0 product in a household, confirm the notification appears with the correct singular/plural wording.
3. With zero missing items, confirm no notification appears.
4. Tap the notification with the app already open in the foreground (tests the `onNewIntent` path) and confirm it navigates to the Missing tab.
5. Force-stop the app, tap the notification again (tests the cold-start `onCreate` path), and confirm it also navigates to the Missing tab.
6. Change the reminder time in Settings while a reminder is already scheduled; confirm (via `adb shell dumpsys jobscheduler`) the job's next-run time actually changed rather than staying pinned to the old schedule.
7. Turn the toggle off; confirm (via `adb shell dumpsys jobscheduler`) the job is no longer listed.
