# Daily missing-items reminder — design

Date: 2026-07-24
Repos touched: `inventory-laravel` (backend), `inventory-android` (client)

## Problem

The app already surfaces "missing items" (mandatory products at quantity 0) via an
in-app Dashboard banner and a dedicated Missing tab, but only while the app is open.
A user who doesn't habitually open the app has no way to be reminded that something
mandatory has run out. This was parked in `inventory-android/ROADMAP.md` on
2026-07-24 alongside the app-update-notifications feature, which established the
notification-channel/WorkManager infrastructure this feature reuses.

## Goals

- Notify the user once a day, at a time they choose, if they currently have one or
  more missing items.
- Let the user turn the reminder on/off and change its time from Settings.
- Reuse the WorkManager + notification-channel pattern already shipped for app
  update notifications, on its own independent schedule and channel.
- Keep the background check cheap — a count, not a full hierarchy walk.

## Non-goals

- No list of item names in the notification (privacy: lock-screen visibility of
  private inventory contents) — count only, per this design's own decision.
- No per-household reminder configuration — missing items are already account-wide
  (not household-scoped) everywhere else in the app; this reminder matches that.
- No server-side persistence of the reminder time/enabled state — this is a
  device-local preference, matching how theme/language preferences already work.
- No change to how "missing" is defined (`is_mandatory = true AND quantity = 0`) —
  reusing the existing definition used by the Dashboard banner and Missing tab.

## Architecture

Two pieces:

1. **`inventory-laravel`**: a new public-to-authenticated-users endpoint,
   `GET /api/v1/missing-items/count`, computing the count server-side in one query
   instead of the client's current full households→locations→shelves→products walk.
2. **`inventory-android`**: a settings-local on/off + time preference, a second
   `CoroutineWorker` scheduled via WorkManager at the user's chosen time (rescheduled
   whenever the time changes, unlike the update checker's fixed 24h/`KEEP` schedule),
   and a second, independent notification channel (`"missing_items"`).

### Data flow

```
Android Settings (toggle + TimePicker)
        |
        v
ReminderSettingsStore (SharedPreferences, device-local)
        |
        v
ReminderScheduler.reschedule() -- enqueueUniquePeriodicWork(REPLACE) with a computed
        |                          initial delay to the next hour:minute occurrence
        v
MissingItemsCheckWorker (daily)
        |
        v
GET /api/v1/missing-items/count  (Sanctum bearer token)
        |
   count > 0? --no--> nothing posted
        |
       yes
        |
        v
Notification on "missing_items" channel, tap -> MainActivity(navigate_to=missing)
                                                 -> Routes.MISSING_ITEMS
```

## Backend (`inventory-laravel`)

**Endpoint**: `GET /api/v1/missing-items/count`

- Auth: `auth:sanctum` (any authenticated user; no household-scoping middleware,
  since missing items are account-wide — same as the existing client-side
  aggregation the Dashboard/Missing tab already perform).
- Query: a single joined query counting `inventory_products` rows where
  `is_mandatory = true AND quantity = 0`, joined through `inventory_shelves` →
  `inventory_storage_locations` → `inventory_households` → the pivot
  `inventory_household_user` filtered to the authenticated user's id. No N+1, no
  full hierarchy serialization.
- Response: `{"count": <int>}`. No error cases beyond the standard 401 for an
  invalid/missing token — this is a plain read.
- Route registration: alongside the other authenticated (non-household-scoped)
  routes in `routes/api.php`, under `middleware('auth:sanctum')`, not inside any
  `households/{household}` group.

**Controller**: `MissingItemsCountController::index()`, thin — one query, one JSON
response, no Form Request needed (no input).

**Testing**: a feature test seeding two households (one with a mandatory/qty-0
product, one without) for the same user, asserting the count reflects both
correctly and excludes non-mandatory or in-stock products; a second test asserting
a user's count never includes another user's households.

## Android (`inventory-android`)

- `data/api/MissingItemsCountApi.kt` — Retrofit, `@GET("missing-items/count")`,
  returns a `MissingItemsCountResponse(count: Int)` DTO, off the existing shared
  Retrofit/OkHttp instance (bearer token attached automatically, unlike the public
  app-version endpoint).
- `data/missingitems/MissingItemsRepository.kt` / `Impl` — `suspend fun count():
  Result<Int>`, catching and logging any failure (never throws), following the same
  graceful-failure posture as `AppUpdateRepository`.
- `data/settings/ReminderSettingsStore.kt` (interface) +
  `SharedPrefsReminderSettingsStore` (impl) — `data class ReminderSettings(enabled:
  Boolean = false, hour: Int = 9, minute: Int = 0)`, `get(): ReminderSettings`,
  `set(settings: ReminderSettings)` — same `SharedPreferences("inventory_settings",
  ...)` file `SharedPrefsThemeModeStore` already uses, new key prefix
  (`missing_items_reminder_*`).
- `work/ReminderScheduler.kt` — `fun reschedule(context: Context, settings:
  ReminderSettings)`: if `!settings.enabled`, calls
  `WorkManager.getInstance(context).cancelUniqueWork("missing_items_check")` and
  returns; otherwise computes the initial delay to the next occurrence of
  `hour:minute` (today if that time hasn't passed yet, tomorrow otherwise) and calls
  `enqueueUniquePeriodicWork("missing_items_check", ExistingPeriodicWorkPolicy.REPLACE,
  PeriodicWorkRequestBuilder<MissingItemsCheckWorker>(24, TimeUnit.HOURS)
  .setInitialDelay(delay, TimeUnit.MILLISECONDS).build())`. `REPLACE` (not `KEEP`,
  unlike the update checker) because a changed time must actually take effect
  immediately rather than being ignored by an already-enqueued instance.
- `work/MissingItemsCheckWorker.kt` — `@HiltWorker`/`@AssistedInject`, calls
  `MissingItemsRepository.count()`; posts a notification on the `"missing_items"`
  channel only when the result is `Ok` and `count > 0`. Title/body: "N items are
  missing" (matching the existing Dashboard banner's wording), no item names.
- Notification channel `"missing_items"` (`IMPORTANCE_DEFAULT`) created once in
  `InventoryApp.onCreate()`, alongside (not merged into) the existing
  `"app_updates"` channel.
- Notification tap: `PendingIntent` carries `Intent(context,
  MainActivity::class.java).putExtra("navigate_to", "missing")` with the usual
  `FLAG_ACTIVITY_NEW_TASK`/`FLAG_ACTIVITY_CLEAR_TOP` flags. `MainActivity` gains a
  minimal one-shot check (a `LaunchedEffect` keyed on the intent, reading
  `intent.getStringExtra("navigate_to")`) that calls
  `navController.navigate(Routes.MISSING_ITEMS)` once the NavHost exists — the first
  such deep-link mechanism in the app; kept intentionally minimal (a single string
  extra, one destination) rather than building a general deep-link router, since
  nothing else needs one yet.
- Settings UI: a new section on `SettingsScreen.kt` (alongside theme/language/
  join-code) — a switch ("Remind me about missing items") and, when on, a row
  showing the chosen time that opens Compose Material3's `TimePickerDialog`. Every
  toggle/time change calls `ReminderScheduler.reschedule()` immediately.

### Error handling

Identical posture to the update-notifications feature: a failed count fetch is
logged and swallowed — no notification, no crash, no user-facing error state for a
background convenience check. WorkManager's own backoff governs retry timing.

### Testing

- `ReminderSchedulerTest` (JVM) — the delay-computation edge cases: chosen time
  already passed today (schedules for tomorrow) vs. still ahead today (schedules
  for today), and the disable path (asserts `cancelUniqueWork` is invoked, not
  `enqueueUniquePeriodicWork`) — using a fake/wrapped `WorkManager` interaction
  point rather than a real `WorkManager` instance (JVM tests have no Android
  runtime `WorkManager` to schedule against).
- `MissingItemsRepositoryTest` (JVM) — fake `MissingItemsCountApi`, asserts a
  successful count is returned and a thrown exception yields a swallowed failure,
  not a crash.
- A settings ViewModel test asserting a toggle/time change calls
  `ReminderScheduler.reschedule()` with the updated settings.
- No new instrumented flow test, matching this repo's existing testing convention
  for this kind of background-scheduling feature (see the app-update-notifications
  spec's identical rationale).

## Rollout note

Scoped as one vertical slice (backend endpoint + Android scheduling/settings/
notification) rather than split further, since the Android side has nothing
meaningful to test against until the count endpoint exists.
