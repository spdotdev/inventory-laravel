# CLAUDE.md — Inventory (Laravel package)

Working agreement for Claude Code. Read before any task. Shared spec lives in the
[`inventory-docs`](https://github.com/spdotdev/inventory-docs) repo
(`planning/project-brief.md`, `specs/data-model.md`, `specs/api-contract.md`).

## What this is
A **Composer package** (`spdotdev/inventory`) — a **headless Laravel API + a marketing
landing page** for a private, multi-user, multi-household **inventory** app. It is
**installed into a host Laravel app (sd-admin)**, not run standalone. **Android
(Kotlin/Compose) is the only API client.** Modelled on `spdotdev/scuttle-dev`.

The product is general-purpose inventory; freezer/fridge/pantry are *example* storage
location types, not the brand.

## Stack
- PHP 8.3+, Laravel 13, Composer `type: library`, namespace `Spdotdev\Inventory\`
- Auto-discovered `InventoryServiceProvider`
- Laravel **Sanctum** (token auth) + Laravel **Socialite** (Google sign-in)
- **MySQL** on the host app's default connection; **Redis** (host) for cache/queue
- Dev/testing via `orchestra/testbench`
- Quality gates: Larastan (max level), Laravel Pint, PHPUnit (critical paths only)
- Deploy: rides the host app (sd-admin) on DigitalOcean EU (AMS3/FRA1) — no separate deploy

## Package shape (mirror scuttle-dev)
```
src/InventoryServiceProvider.php      register/boot, loadRoutes, loadViews, mergeConfig, publishes, commands
src/Http/Controllers/                 LandingController (web), Api/* (api/v1)
src/Http/Middleware/                  EnsureHouseholdMember
src/Models/                           User, Household, StorageLocation, Shelf, Product
src/Console/Commands/                 household create, etc.
config/inventory.php                  'domain' => env('INVENTORY_DOMAIN', ...)
routes/web.php                        Route::domain(config('inventory.domain'))->middleware('web')  -> landing
routes/api.php                        same domain ->prefix('api/v1')->middleware('api')             -> API
database/migrations/                  inventory_* tables
resources/views/                      landing page (Frost-styled "coming soon")
public/                               landing assets (publishable)
```

## Hard rules — LOCKED, do not relitigate or "improve"
- **Package, not an app.** No standalone `laravel new` skeleton; depend on the host app.
- **Server-authoritative, always-online.** NO offline store, NO sync, NO conflict resolution.
- **Host-based routing on `config('inventory.domain')`.** `/` = landing page,
  `/api/v1/*` = API. Never hardcode the domain.
- **All package tables prefixed `inventory_`** to avoid colliding with host tables.
- **Own auth** (`inventory_users`) — email/password + Google; issues Sanctum tokens.
  Independent of the host app's users.
- **Multi-tenant by Household.** Every resource belongs to a household; nothing belongs
  directly to a user. Tenancy enforced by `household.member` middleware on
  `/api/v1/households/{household}/*` — verify membership BEFORE any resource access.
- **API versioned: `/api/v1`, backward compatible** — a shipped Android build updates
  on the user's schedule, not ours.
- **Concurrency: last-write-wins.** No version/If-Match/optimistic-locking.
- **Hard deletes, `ON DELETE CASCADE`** (location → shelves → products). No soft deletes.
- **No roles/permissions** — all household members equal.
- Secrets via `.env` only. Validate input at every boundary.

## Scope guardrails — deliberately cut; refuse to add
No expiry/reminders, no recipes, no shopping list, no activity/audit log, no GDPR
machinery (private for now — flag if it goes public). Web/Filament admin UI is Phase 2,
not now.

## Data model & API
Canonical and authoritative in `inventory-docs/specs/`. Do not duplicate the schema or
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

## Build order (start here)
1. Package skeleton + service provider + config + host-based route groups (web + api/v1).
2. Landing page (Blade + assets).
3. Migrations (`inventory_*`) + models.
4. `household.member` middleware.
5. Auth: register/login/logout (Sanctum) + Google (`/auth/google` via Socialite).
6. Households (create/list/invite/join/leave) + search.
7. Locations / shelves / products CRUD + add/remove/move.
8. Artisan commands (household create, …).
**Start with the skeleton + migrations + tenancy middleware.**
