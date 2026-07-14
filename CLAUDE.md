# CLAUDE.md — Inventory (Laravel package)

Working agreement for Claude Code. Read before any task. Canonical spec lives in this
repo's `docs/` (`docs/planning/project-brief.md`, `docs/specs/data-model.md`,
`docs/specs/api-contract.md`) — canonical for the whole product, including
`inventory-android`.

## What this is
A **Composer package** (`spdotdev/inventory`) — a **headless Laravel API + a web
account/household UI + a marketing landing page** for a private, multi-user,
multi-household **inventory** app. It is **installed into a host Laravel app
(sd-admin)**, not run standalone. **Android (Kotlin/Compose) is the only API client**;
the web UI is a session-guarded Blade surface on the same domain. Modelled on
`spdotdev/scuttle-dev`.

The product is general-purpose inventory; freezer/fridge/pantry are *example* storage
location types, not the brand.

## Stack
- PHP 8.3+, Laravel 13, Composer `type: library`, namespace `Spdotdev\Inventory\`
- Auto-discovered `InventoryServiceProvider`
- Laravel **Sanctum** (token auth) + Laravel **Socialite** (Google sign-in)
- **MySQL** on the host app's default connection; **Redis** (host) for cache/queue
- Dev/testing via `orchestra/testbench`
- Quality gates: Larastan (max level), Laravel Pint, PHPUnit (critical paths only)
- Deploy: rides the host app (sd-admin) on DigitalOcean EU — no separate deploy. **Live
  in production** at `inventory.scuttle.dev`; see `docs/deploy-runbook.md`
- Live updates: Laravel **Reverb** (Pusher protocol) on the host; model observers
  broadcast `household.changed` on private `inventory.household.{id}` channels

## Package shape (mirror scuttle-dev)
```
src/InventoryServiceProvider.php      register/boot, loadRoutes, loadViews, mergeConfig, publishes, commands
src/Http/Controllers/                 LandingController (web), Api/* (api/v1)
src/Http/Middleware/                  EnsureHouseholdMember
src/Models/                           User, Household, StorageLocation, Shelf, Product
src/Console/Commands/                 household create, etc.
config/inventory.php                  'domain' => env('INVENTORY_DOMAIN', <APP_URL host>)
routes/web.php                        Route::domain(config('inventory.domain'))->middleware('web')  -> landing
routes/api.php                        same domain ->prefix('api/v1')->middleware('api')             -> API
database/migrations/                  inventory_* tables
resources/views/                      landing page (Frost-styled "coming soon")
public/                               landing assets (publishable)
```

## Hard rules — LOCKED, do not relitigate or "improve"
- **Package, not an app.** No standalone `laravel new` skeleton; depend on the host app.
- **Server-authoritative, always-online.** NO offline store, NO sync, NO conflict resolution.
- **Host-based routing on `config('inventory.domain')`**, which **defaults to the host
  app's own domain** (`APP_URL` host) and is overridable via `INVENTORY_DOMAIN`. `/` =
  landing page, `/api/v1/*` = API. Never hardcode the domain.
- **All package tables prefixed `inventory_`** to avoid colliding with host tables.
- **Own auth** (`inventory_users`) — email/password + Google; issues Sanctum tokens.
  Independent of the host app's users.
- **Multi-tenant by Household.** Every resource belongs to a household; nothing belongs
  directly to a user. Tenancy enforced by `household.member` middleware on
  `/api/v1/households/{household}/*` — verify membership BEFORE any resource access.
- **API versioned: `/api/v1`, backward compatible** — a shipped Android build updates
  on the user's schedule, not ours.
- **Concurrency: last-write-wins.** No version/If-Match/optimistic-locking.
- **Soft deletes on the hierarchy** (locations/shelves/products carry `deleted_at`
  + `deletion_batch_id`). Reversed 2026-07-13: hard cascade deletes silently
  destroyed a location's whole subtree with no confirmation and no undo. The
  `ON DELETE CASCADE` FKs remain — a soft delete is an `UPDATE` and never fires
  them, and they stay correct for the retention purge (`inventory:deleted:prune`).
  Deleting a non-empty container REQUIRES an explicit strategy; the server never
  guesses. Households themselves are still hard-deleted when the last member leaves.
  `deletion_batch_id` on the shelf/location delete endpoints is **optional**, not
  required: a shipped Android build (v0.1.8) sends a bodyless `DELETE` with no batch
  id, and the API-versioned/backward-compatible rule above means that build keeps
  working — the server mints its own batch-of-one uuid when the client omits it, so
  the row still lands genuinely restorable. A client-supplied id is always used
  verbatim (never overridden), which is what lets several requests from one user
  gesture share a batch for Undo. See `docs/specs/api-contract.md` for the full
  contract.
- **Roles/permissions: coming.** `HouseholdPolicy@restructure` is the seam — today
  it grants any member (all members still equal in practice). Owner/Admin/Member
  land in the roles spec; change that method body, not the call sites.
- Secrets via `.env` only. Validate input at every boundary.

## Scope guardrails — deliberately cut; refuse to add
No expiry/reminders, no recipes, no shopping list, no activity/audit log, no GDPR
machinery (private for now — flag if it goes public).
**Phase 2 unlocked 2026-07-10** (user decision) and since shipped: the web
account/household UI (thin Blade, session guard) and `low_stock_threshold`. The API
stays headless and versioned; the web surface is additive on the same domain, never a
breaking change to `/api/v1`.

## Data model & API
Canonical and authoritative in `docs/specs/`. Do not duplicate the schema or
route table here — follow those files. Summary: `inventory_users`,
`inventory_households`, `inventory_household_user`, `inventory_storage_locations`,
`inventory_shelves`, `inventory_products`, Sanctum `personal_access_tokens`.

## Landing page
`/` on `inventory.{domain}` serves a modern, professional **"coming soon"** page that
hints at an Android inventory-management app **without disclosing feature detail**.
Frost palette (icy-blue #7dd3fc, Plus Jakarta Sans). Blade view + publishable assets.

## Conventions
- Explicit over magic; SRP; document the *why*, not the *what*.
- Form Requests for validation; API Resources for responses; route-model binding scoped to household.
- Tests cover critical paths + obvious failure modes only: tenancy isolation, auth
  (email/password + Google), stock floor at 0, cascade deletes, join-by-code. No trivial tests.

## Status
**Live in production** at `inventory.scuttle.dev` (rides sd-admin's deploy). All of the
build order below is implemented: the package skeleton + provider + host-based route
groups, landing page, `inventory_*` schema + models, `household.member` tenancy
middleware, auth (Sanctum + Google), households (create/list/invite/join/leave) +
search, locations/shelves/products CRUD + add/remove/move + image upload, password
reset, client-error intake, the admin API, MCP servers (HTTP `/mcp` + the standalone
stdio `inventory-mcp`), and the artisan `inventory:household:*` commands — plus Phase 2:
the session-guarded web UI, `low_stock_threshold`, and Reverb live updates. Gated by
Pint + Larastan + PHPUnit (SQLite for the fast job, a real MySQL service job too).
Forward-looking work is in [`ROADMAP.md`](ROADMAP.md); shipped history in
[`BACKLOG.md`](BACKLOG.md).

## Build order (historical — all shipped)
1. Package skeleton + service provider + config + host-based route groups (web + api/v1).
2. Landing page (Blade + assets).
3. Migrations (`inventory_*`) + models.
4. `household.member` middleware.
5. Auth: register/login/logout (Sanctum) + Google (`/auth/google` via Socialite).
6. Households (create/list/invite/join/leave) + search.
7. Locations / shelves / products CRUD + add/remove/move.
8. Artisan commands (household create, …).
