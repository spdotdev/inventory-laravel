# CLAUDE.md — Household Inventory API

Working agreement for Claude Code. Read this before any task. Full spec lives in
`/docs/project-brief.md` and `/docs/product-description.md`.

## What this is
A **headless Laravel API + database** for a private, multi-user household inventory
app. **Android (Kotlin/Compose) is the only client; this repo is backend only — no web UI.**

## Stack
- PHP 8.3+, Laravel (current stable), Composer
- Laravel Sanctum — token auth (first-party mobile; not Passport, not SPA cookie mode)
- PostgreSQL (or MySQL) + Redis (cache/queue)
- Quality gates: PHPStan (max level), PHP-CS-Fixer, PHPUnit (critical paths only), Xdebug
- Deploy target: DigitalOcean, EU region (AMS3/FRA1), IaC via doctl/Terraform

## Hard rules — LOCKED, do not relitigate or "improve"
- **Server-authoritative, always-online.** NO offline store, NO sync, NO conflict resolution.
- **Multi-tenant by Household.** Every resource belongs to a household; nothing belongs
  directly to a user. Tenancy enforced by middleware on `/api/v1/households/{household}/*`
  — verify membership BEFORE any resource access.
- **API versioned from day one: `/api/v1`.** Keep it backward compatible — a shipped
  Android app updates on the user's schedule, not ours. Breaking the contract bricks old builds.
- **Concurrency: last-write-wins.** No version/If-Match/optimistic-locking checks.
- **Hard deletes, `ON DELETE CASCADE`** down the tree (location → shelves → products).
  No soft deletes.
- **No roles/permissions** — all household members are equal.
- Secrets via `.env` only. Never hardcode. Validate input at every boundary.

## Scope guardrails — these were deliberately cut; refuse to add them
No expiry/expiry reminders, no recipes, no shopping list, no activity/audit log,
no GDPR machinery (private app for now — flag if it ever goes public).

## Data model
```
users             (id, email, password_hash, name, created_at)
households        (id, name, join_code, created_at)        -- join_code drives invite link + QR
household_user    (household_id, user_id, joined_at)        -- composite PK, NO role column
storage_locations (id, household_id, name, type[freezer|fridge|pantry|other], created_at)
shelves           (id, location_id, name, position, created_at)
products          (id, shelf_id, name, quantity, created_at, updated_at)  -- qty 0 = out of stock, row kept
personal_access_tokens (Sanctum)
```
All FKs `ON DELETE CASCADE`. `quantity` never goes below 0.

## API surface (build these, under `/api/v1`)
```
POST   /auth/login                          -> Sanctum token
POST   /auth/logout                         -> revoke
GET    /households
POST   /households
GET    /households/{household}/invite        -> { code, link }   (client renders QR from link)
POST   /households/join        { code }      -> join by code
DELETE /households/{household}/leave         -> self
GET    /households/{household}/search?q=     -> products + location path (location › shelf)
       /households/{household}/locations[/{location}]                    CRUD
       /households/{household}/locations/{location}/shelves[/{shelf}]    CRUD
       /households/{household}/shelves/{shelf}/products[/{product}]      CRUD
POST   .../products/{product}/add     { amount }   -> increment
POST   .../products/{product}/remove  { amount }   -> decrement (floor 0)
POST   .../products/{product}/move    { shelf_id } -> relocate within household
```
Middleware chain: `auth:sanctum` → `household.member({household})` → resource policy.

## Conventions
- Explicit over magic; SRP; document the *why*, not the *what*.
- Form Requests for validation; API Resources for responses; route-model binding scoped to household.
- Tests cover critical paths + obvious failure modes only: tenancy isolation, auth,
  stock floor at 0, cascade deletes, join-by-code. No trivial/getter-setter tests.

## Current task
Scaffold the API: migrations for the data model above, Sanctum auth, the
`household.member` middleware, the versioned `/api/v1` route group, and controllers
for locations / shelves / products CRUD + add/remove/move + search.
**Start with the migrations and the tenancy middleware.**
