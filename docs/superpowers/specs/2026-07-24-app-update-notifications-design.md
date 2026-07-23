# App update notifications — design

Date: 2026-07-24
Repos touched: `inventory-laravel` (backend + admin API), `inventory-mcp` (release-management tools), `inventory-android` (client)

## Problem

The Android app has no way to tell a user a new version exists. Distribution is
tag-driven GitHub prerelease APKs (debug-signed, no Play Store) — a user who doesn't
watch the repo has no signal a new build is available, including for breaking releases
that require an update before the app can keep working correctly.

## Goals

- Notify the user of a new release even when the app is closed (periodic background
  check, not push-based — no FCM infra needed).
- Show a popup on app open when a new release exists, with its changelog.
- Distinguish **optional** updates (dismissable, app keeps working) from **breaking**
  updates (hard block — app unusable until updated).
- Let the user manage this via a normal Android notification channel ("App Updates"),
  separate from other channels planned later (e.g. a daily missing-items reminder).
- Let releases be published from Claude via the existing `inventory-mcp` server,
  without the backend needing GitHub API credentials.

## Non-goals

- No FCM / push notifications (out of scope for now — periodic pull is sufficient at
  this app's scale and avoids new infra).
- No Play Store / AAB release pipeline (unrelated to this feature — stays a debug-APK
  distribution, per `inventory-release-deferred`).
- Backend does not call the GitHub API itself; the release's `download_url` is supplied
  by whoever publishes the release (human or MCP tool call), copied from the GitHub
  prerelease asset.

## Architecture

Three pieces:

1. **`inventory-laravel`**: a new `app_releases` table + a public read endpoint
   (`GET /api/v1/app-version`) the app polls, plus admin write endpoints for
   publishing/editing releases.
2. **`inventory-mcp`**: new tools (`create_app_release`, `list_app_releases`,
   `update_app_release`) wrapping the admin endpoints, so a release can be published by
   asking Claude, following the same pattern as the existing household/location/
   shelf/product admin tools in `src/server.ts`.
3. **`inventory-android`**: a repository + comparator to classify the check result, a
   `WorkManager` periodic job for the closed-app notification, and a foreground dialog
   for the open-app popup. Both paths share the same repository/comparator — no
   duplicated version logic.

### Data flow

```
Claude (MCP) --create_app_release--> Laravel admin API --> app_releases table
                                                                  |
                                                    GET /api/v1/app-version
                                                                  |
                    +---------------------------------------------+
                    |                                              |
         Android: app open                          Android: WorkManager (24h)
         (AppUpdateRepository.check())                (AppUpdateCheckWorker)
                    |                                              |
           classify vs BuildConfig.VERSION_CODE          classify vs BuildConfig.VERSION_CODE
                    |                                              |
         none -> nothing                                none -> nothing
         optional -> dismissable dialog                 any new -> notification on
                     with changelog                                "app_updates" channel
         breaking -> non-dismissable dialog,
                     "Update now" only
```

## Backend (`inventory-laravel`)

**Migration** — `app_releases`:

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `version_code` | int, unique | matches Android `versionCode` |
| `version_name` | string | e.g. `"0.1.22"` |
| `is_breaking` | bool, default false | |
| `min_supported_version_code` | int, nullable | only meaningful when `is_breaking`; installed `versionCode` below this is a hard block |
| `changelog` | text | shown in both the dialog and (truncated) the notification |
| `download_url` | string | GitHub prerelease APK asset URL |
| `published_at` | timestamp, nullable | null = draft, not yet returned by the public endpoint |

**Endpoints**:
- `GET /api/v1/app-version` — public, no auth. Returns the highest `version_code` row
  where `published_at` is not null. 404-equivalent (`null` body, 200) if no release
  exists yet — the app treats that as "no update available", not an error.
- `POST /api/v1/admin/app-releases` — creates a row. Same admin-role gate the other
  admin-only writes in this API already use.
- `GET /api/v1/admin/app-releases` — list all (drafts included), for review before
  publishing.
- `PATCH /api/v1/admin/app-releases/{id}` — edit any field, including setting
  `published_at` (publish a draft).

Validation: `version_code` unique; `min_supported_version_code` required when
`is_breaking` is true, forbidden otherwise (mirrors this codebase's existing
"explicit strategy required" validation style for nullable state-dependent fields).

## MCP (`inventory-mcp`)

Three new tools in `src/server.ts`, following the existing registration pattern
(zod schema per input, thin wrapper over an admin HTTP call):

- `create_app_release` — params: `version_code`, `version_name`, `is_breaking`,
  `min_supported_version_code` (optional), `changelog`, `download_url`, `publish`
  (bool, default false — if true, sets `published_at` immediately instead of leaving
  a draft).
- `list_app_releases` — no params; returns all releases (drafts + published) for review.
- `update_app_release` — params: `id` + any of the above fields; also usable to
  publish a draft (`publish: true`) after review.

## Android (`inventory-android`)

- `data/appupdate/AppReleaseApi.kt` — Retrofit interface, single `GET app-version` call
  (no auth header — matches the endpoint being public).
- `data/appupdate/AppUpdateRepository.kt` — calls the API, compares
  `BuildConfig.VERSION_CODE` against the response via
  `data/appupdate/VersionComparator.kt`:
  - no release, or `version_code <= installed` → `UpdateStatus.None`
  - `version_code > installed`, not breaking, or `installed >= min_supported_version_code`
    → `UpdateStatus.Optional`
  - `version_code > installed` and `installed < min_supported_version_code` →
    `UpdateStatus.Breaking`
- `work/AppUpdateCheckWorker.kt` — `CoroutineWorker`, calls the repository; on
  `Optional`/`Breaking` posts a notification on the `"app_updates"` channel (title/body
  differ by status, changelog truncated to first line or ~100 chars). Scheduled from
  `InventoryApplication.onCreate()` as
  `PeriodicWorkRequest(24, HOURS)` with `ExistingPeriodicWorkPolicy.KEEP` so app
  restarts don't reschedule/duplicate it.
- `ui/appupdate/UpdateDialog.kt` — Compose dialog, driven by a `MainActivity`-level
  `LaunchedEffect(Unit)` that calls the repository once per process start (not per
  screen navigation). Breaking: `DialogProperties(dismissOnBackPress = false,
  dismissOnClickOutside = false)`, single "Update now" button, changelog scrollable.
  Optional: normal dismissable, "Later" + "Update now".
- `ui/appupdate/UpdateInstaller.kt` — downloads `download_url` via `DownloadManager`
  into the app's external cache dir, then fires
  `Intent(Intent.ACTION_VIEW)` with a `FileProvider` content URI and
  `FLAG_GRANT_READ_URI_PERMISSION`, requesting the `REQUEST_INSTALL_PACKAGES`
  permission (declared in the manifest; Android surfaces its own "allow this source"
  prompt on first use — no custom permission UI needed).
- Notification channel: `"app_updates"` (`IMPORTANCE_DEFAULT`), created once in
  `InventoryApplication.onCreate()` alongside the WorkManager scheduling — this is the
  one channel for both optional and breaking notices per the design discussion; future
  categories (e.g. missing-items daily reminder) get their own channel later, not
  folded into this one.

### Error handling

A failed check (network error, non-200, malformed body) is swallowed at the
repository boundary and logged — no dialog, no notification, no crash. WorkManager's
own default backoff policy governs retry timing for the periodic job; the foreground
dialog path simply shows nothing that launch if the check fails, matching this app's
existing "surface real failures, but a background convenience check failing silently
is not a real failure" posture.

### Testing

- `VersionComparatorTest` (JVM) — table-test the three `UpdateStatus` outcomes
  including the `is_breaking` + `min_supported_version_code` edge cases (equal to
  installed, one above, one below).
- `AppUpdateRepositoryTest` (JVM) — fake `AppReleaseApi`, asserts classification wiring
  and that a failed call yields `UpdateStatus.None`-equivalent (no crash, no throw
  surfaced to caller).
- No new instrumented flow test — dialog visibility is a deterministic function of
  `UpdateStatus` state, covered at the unit level per this repo's existing testing
  conventions (see `CLAUDE.md` → "Testing lessons").

## Rollout note

This is being scoped as its own vertical slice (backend table/endpoints + MCP tools +
Android client) rather than split further — all three pieces are needed together for
the feature to be usable end-to-end (a release can't be tested from Android until the
admin endpoints and at least one MCP publish tool exist).
