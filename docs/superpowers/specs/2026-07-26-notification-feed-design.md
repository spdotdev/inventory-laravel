# Notification feed + expanded notifications menu — design

Date: 2026-07-26
Status: approved (user-reviewed in session)
Repos: inventory-laravel (feed backend), inventory-android (delivery + settings UI)

## Goal

Expand the app's notifications from two (app-update, daily missing-items reminder) to a
full set, all user-controllable in More → Notifications:

1. App-update notification toggle (channel already exists, no toggle today).
2. Low-stock daily reminder (toggle + time, default **on at 18:00**).
3. Member joined (invite accepted) — per-event, on by default, for Owners/Admins.
4. Household activity digest — off by default; one digest notification per poll cycle.
5. Role changes (promote/demote/ownership transferred to you) — per-event, on by default.
6. Weekly summary — off by default; "Your week: X products, Y missing, Z running low".

Expiry-date reminders, shopping lists, recipes remain out of scope (standing guardrail;
the reminders here are the same narrow carve-out class as the 2026-07-24 missing-items
reminder).

## Delivery architecture — hybrid (decision)

Server-side **notification feed** (durable, FCM-ready) delivered for now by **polling
WorkManager**; FCM can later replace the poll without changing the feed. No Firebase
dependency in this iteration.

## Backend (inventory-laravel)

### `notifications` table

Per-user rows: `id`, `user_id` (FK), `household_id` (nullable FK), `type` (string),
`payload` (JSON), `created_at`, `read_at` (nullable). Indexed on
(`user_id`, `created_at`).

Writers (in existing code paths, same transaction as the triggering change):

- `member_joined` — on invite acceptance; one row per Owner/Admin of the household
  (not the joiner).
- `role_changed` — on promote/demote/transfer; one row for the affected user only.
- `activity` — on deletion batch, move batch, or member departure; one row per batch
  per *other* member (the actor gets no row). Payload carries actor name, action kind,
  and item count — the client digests further.

### Endpoints (`/api/v1`, Sanctum auth, user-scoped — NOT household-URL-scoped)

- `GET /notifications?after=<cursor>` — entries with `id > cursor`, oldest→newest,
  cursor-paginated (reuse existing pagination conventions; page size 50). Response
  rows: `id`, `type`, `household` (id+name), `payload`, `created_at`.
- `GET /low-stock/count` — total low-stock products across the caller's households,
  sibling of `GET /missing-items/count`. Reuse the existing low-stock query. If an
  existing endpoint already exposes a cheap account-wide count, reuse it instead and
  skip this route.

Weekly summary needs **no new backend**: the client composes it from
`missing-items/count`, `low-stock/count`, and the products count already available to
the dashboard.

### Retention

Scheduler prune: delete feed rows older than 30 days (append to the existing scheduled
tasks).

## Android (inventory-android)

### Workers

- **`NotificationFeedWorker`** — periodic, every 6 h, network-required, registered like
  `AppUpdateCheckWorker` (CANCEL_AND_REENQUEUE gotcha applies). Each run:
  1. `GET /notifications?after=<lastSeenId>` (cursor persisted in a SharedPrefs store,
     pattern of `SharedPrefsReminderSettingsStore`).
  2. Group by type + household, honouring per-type toggles:
     - `member_joined`, `role_changed` → one notification per event.
     - `activity` → one digest per household: "N changes in <household>".
  3. Weekly summary: if enabled and this is the first successful run past the
     configured day+time since the last posted summary, fetch the three counts and
     post the summary. Last-posted marker persisted alongside the cursor.
  4. Advance the cursor **only after** notifications are posted (a failed run retries
     with backoff and catches up next cycle; duplicates are impossible because the
     cursor is the dedup).
- **Low-stock daily reminder** — clone of the missing-items reminder:
  `ReminderScheduler`-style one-shot chain at the user's time, hits the low-stock
  count, notifies when > 0. Default ON at 18:00.
- **App-update toggle** — `AppUpdateCheckWorker` checks a new device-local boolean
  before posting; the on-open update dialog is unaffected.

### Notification channels

Existing `app_updates`, `missing_items_reminder`; new `low_stock_reminder`,
`household_events` (joined + role changes share one channel), `household_activity`,
`weekly_summary`. One channel per user-facing toggle group gives OS-level control for
free.

### Settings — More → Notifications screen

Five sections, existing toggle(+time) row pattern, EN + NL strings:

| Section | Controls | Default |
|---|---|---|
| Missing items | toggle + time | as today |
| Low stock | toggle + time | on, 18:00 |
| Household | "Member joined & role changes" toggle; "Activity digest" toggle | on; off |
| Weekly summary | toggle + day picker + time | off, Sunday 18:00 |
| App updates | toggle | on |

All preferences device-local (SharedPrefs stores + ViewModels). Deep-link taps: feed
notifications open the app (household events → that household where practical;
summary/low-stock → missing-items/low-stock views, matching the missing-items
reminder's deep-link pattern).

## Error handling

- Poll failure → WorkManager retry/backoff; cursor untouched, next cycle catches up.
- Unknown feed `type` → skipped silently, cursor still advances past it (forward
  compatibility with future event types).
- Notifications permission revoked → workers still run and advance cursor; posting is
  a no-op (matches existing reminder behaviour).

## Testing

- **Laravel**: feature tests per writer (row created for the right users and no one
  else, actor excluded, transactionality), endpoint tests (auth, user-scoping — user A
  never sees user B's rows), cursor pagination, prune job.
- **Android JVM**: grouping/digest logic, cursor advance-only-on-success, weekly
  summary due-time logic, settings stores, per-house-rules byte-level DTO tests,
  `StringResourceUsageTest` covers new strings.
- **Instrumented**: settings screen flow test (toggles persist), re-checking existing
  flow tests that touch the Notifications screen since its layout changes.

## Out of scope

FCM push (feed schema is ready for it), per-household notification preferences,
server-stored preferences, read-state UI/in-app notification center (`read_at` exists
for future use only).
