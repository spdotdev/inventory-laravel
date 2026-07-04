# Backlog

> Wishes and history. Companion to [`ROADMAP.md`](ROADMAP.md) (forward-looking
> commitments). This file holds **Ideas** (detailed proposals + a brainstorm parking lot)
> and **Done** (shipped milestones). Split per the convention: phases are commitments,
> ideas are wishes — don't let them contaminate each other's reading.

Markers: 💡 IDEA · ✅ DONE.

---

## Ideas — detailed proposals

### 💡 CRUD design for locations / shelves / products

**What.** Decide and document *how* the package implements CRUD for the three nested
resources (`storage_locations` → `shelves` → `products`) before writing controllers.
Cover: controller style (invokable vs resource controllers), route-model binding scoped
to `{household}`, Form Request validation classes, API Resource response shapes, the
nested-route structure from `specs/api-contract.md`, and the `add`/`remove`/`move`
stock actions as dedicated endpoints (not generic update).

**Why.** The three resources share a tenancy + nesting pattern; settling it once keeps
all controllers consistent and avoids re-litigating per resource. Locking the response
shapes early also unblocks the Android client (it codes against a stable contract).

**Where it touches.**
- `src/Http/Controllers/Api/` — Location/Shelf/Product controllers.
- `src/Http/Requests/` — Store/Update form requests per resource.
- `src/Http/Resources/` — API resources (+ a search-result resource with the
  `location › shelf` path).
- `src/Http/Middleware/EnsureHouseholdMember` + route-model binding scoping.

**Risks.** Over-abstracting a shared base controller before the shapes are proven;
leaking out-of-tenant existence via 403 instead of 404; the `move` action crossing
household boundaries (must validate the target shelf belongs to the same household).

**Effort.** ~0.5 day to design + document; CRUD itself follows the build order.

**Kill criterion.** If a generic resource controller can't express the stock actions
cleanly, drop the abstraction and write explicit per-resource controllers.

### 💡 Web account creation + household management on the landing page

**What.** Brainstorm letting users **register an account and manage households from the
web** (the landing page / host app), not only the Android client — e.g. sign up, create
a household, view/copy the join code + link + QR, manage members, sign in with Google.

**Why.** Lowers onboarding friction (a person can set up a household on a laptop and
invite others before anyone installs the app) and is a natural step toward the deferred
web/Filament UI. Also gives the landing page a real reason to exist beyond marketing.

**Where it touches.**
- Decision: reuse the headless API behind a thin Blade/Livewire/Filament web layer, or
  build server-rendered web auth separately. Must stay consistent with the
  Sanctum-token model (web sessions vs tokens).
- `routes/web.php`, new web controllers/views, and possibly Filament resources (overlaps
  the Phase 2 admin-UI idea below).
- Auth: web session guard alongside the API token guard on the same `inventory_users`.

**Risks.** Scope creep — this reopens the "headless only" decision (D-005/D-029 family).
Two auth surfaces (web session + API token) to keep in sync. Decide MVP boundary
carefully; may belong wholly in Phase 2.

**Effort.** Brainstorm first (½ day); implementation is a multi-day feature, likely Phase 2.

**Kill criterion.** If the Android app fully covers onboarding and web sign-up sees no
demand, keep the landing page marketing-only.

> **Deferred 2026-07-04** (backlog-sweep decision) — stays **LOCKED Phase-2**. `CLAUDE.md`
> holds the package headless/server-authoritative rule and "Web/Filament admin UI is Phase 2,
> not now"; building this now would reopen the headless-only decision (D-005/D-029) and add a
> second auth surface. Not implemented on purpose.

---

## Ideas — parking lot
- 💡 Filament admin resources for households/locations/products (Phase 2 web UI).
  *Deferred 2026-07-04 (backlog-sweep decision): stays LOCKED Phase-2 per `CLAUDE.md`
  ("Web/Filament admin UI is Phase 2, not now"). Not implemented on purpose.*

---

## Done
- ✅ `2026-07-04` — **Search results now carry navigation IDs** (wave-2 W1). `SearchResultResource` returned
  only `id,name,quantity,location,shelf,path` — not `household_id`/`location_id`/`shelf_id`. The Android
  `SearchScreen` only makes a hit tappable when `household_id`+`shelf_id` are present, so against the real
  backend **every search result was a dead card** — the headline "tap a hit to jump to the product" flow
  silently did nothing. It looked green only because the instrumented fixture hand-injected those IDs (this
  is the "flaky SearchFlowTest nav" wave-1 T16 waved off — actually a contract bug). Added the three nav IDs
  to the resource (additive, backward-compatible), asserted them in `SearchTest` so a mock can't mask it
  again, and reconciled `api-contract.md` with the full Search-result shape. Pint + Larastan green; DB test on CI.
- ✅ `2026-07-04` — **Fixed unsigned-underflow in atomic `remove()`** (caught by the new T15 MySQL CI job).
  The T3 decrement used `CASE WHEN quantity - N < 0 …`; because `quantity` is `BIGINT UNSIGNED`, MySQL
  (strict mode) threw `SQLSTATE[22003] value out of range` evaluating `quantity - N` when N > quantity —
  SQLite has no unsigned ints so it silently passed. Rewrote to compare **before** subtracting
  (`CASE WHEN quantity < N THEN 0 ELSE quantity - N END`), so the subtraction only runs where it's
  non-negative. Portable to both engines. Exactly the class of bug T15 was added to catch — the MySQL job
  did its job on the very first CI run. Pint + Larastan green; MySQL suite re-runs on CI.
- ✅ `2026-07-04` — **Corrected stale status docs** (gap analysis T18). `CLAUDE.md` framed the work as a
  "Build order (start here)" as if nothing was built, and the `README` said "skeleton". Added a `## Status`
  (functionally-complete MVP, CI-green), reframed the build order as "historical — all shipped", and
  rewrote the README status block to list the actual shipped surface (auth, schema, CRUD, image upload,
  search, password reset, admin API, MCP, commands; Pint/Larastan/PHPUnit + MySQL CI).
- ✅ `2026-07-04` — **MySQL CI job** (gap analysis T15). Tests ran only on testbench's in-memory SQLite,
  but prod is MySQL — the ENUM storage-type column, `ON DELETE CASCADE` down the location→shelf→product
  tree, and the migrations themselves had never actually executed on MySQL in CI, so an engine-specific
  break could pass CI and fail prod. Added a `mysql` job to `ci.yml` with a health-gated MySQL 8 service
  running the **full** PHPUnit suite against it. `TestCase.defineEnvironment` now honors
  `DB_CONNECTION=mysql` (+ `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`) to flip the driver; the default
  remains in-memory SQLite so the fast `quality` job is unchanged. `RefreshDatabase` migrates the
  `inventory_*` schema on the real engine before each test. YAML validated + Pint/Larastan green locally;
  the MySQL suite runs on CI (local PHP has no pdo_mysql/pdo_sqlite).
- ✅ `2026-07-04` — **Health check now probes the database** (gap analysis T14). `HealthController` returned
  `{status: ok}` unconditionally — an app with a dead DB still reported healthy. It now runs a `SELECT 1`
  and returns `{name, api, status, database}`: DB reachable → 200 `status: ok, database: ok`; DB
  unreachable → **503** `status: error, database: unavailable`, with the raw exception logged via
  `report()` and never leaked into the response. Redis was intentionally left unprobed — the package
  doesn't own the host app's cache/queue config. `HealthCheckTest` mocks the `DB` facade so both the
  healthy and DB-down paths run without a real driver — **ran green locally** (2 tests), a rare case
  where a DB-adjacent test isn't CI-only. Contract (`api-contract.md`) updated.
- ✅ `2026-07-04` — **Security-flow test coverage** (gap analysis T8). Filled the two untested
  security surfaces. `ForgotPasswordTest`: a known email stores a hashed reset token and sends
  `PasswordResetMail`; an unknown email returns the **same 200** with no stored row and no mail
  (no user-enumeration signal); a malformed email 422s. `AdminApiTest`: the static-bearer admin
  API rejects an absent/wrong token (401), is **disabled** (503) when `inventory.admin_token` is
  unconfigured, lists with a valid token, and its destructive deletes behave — deleting a
  household cascades the whole location→shelf→product tree, deleting a user drops their
  memberships but keeps the shared household. (ResetPassword + ClientError were already covered.)
  Pint + Larastan green locally; DB tests on CI.
- ✅ `2026-07-04` — **Product image upload endpoint** (gap analysis T7). The Android client already
  posted a multipart photo to `POST …/products/{product}/image`, but no route/controller existed and
  `image_url` was never populated — a dead end. Implemented the server side: the route sits with
  add/remove/move inside the `household.member` + `scopeBindings` group (non-member → 404), and
  `ProductController::image` validates a single `image` part (`mimetypes:image/jpeg,image/png,image/webp`
  — deliberately not the `image` rule, which needs GD/`getimagesize`, so `UploadedFile::fake()->create()`
  works in CI without GD), stores it on the configured disk (`INVENTORY_IMAGE_DISK`, default `public`;
  `INVENTORY_IMAGE_MAX_KB` cap, default 5 MB), sets `image_url` to the file's absolute URL, deletes any
  previously-stored file, and returns the refreshed `ProductResource`. `image_url` stays out of the
  create/update Form Request (managed only here). `ProductImageTest` covers upload-sets-url + file
  stored, replace-deletes-old, non-image 422, missing-part 422, non-member 404. Contract + data-model
  reconciled. Pint + Larastan green locally; DB tests on CI.
- ✅ `2026-07-04` — **Atomic stock `remove`** (gap analysis T3). `ProductController::remove` did a
  read-modify-write (`quantity = max(0, q - amount); save()`) — two concurrent removes read the same
  start value and one decrement was lost, leaving stock too high. Now a single atomic UPDATE using a
  portable `CASE WHEN quantity - N < 0 THEN 0 ELSE quantity - N END` (not MySQL-only `GREATEST`, so it
  also runs on the SQLite test DB), floored at 0, returning the refreshed row. (`move` only writes the
  `shelf_id` column via `save()` — genuine last-write-wins per D-011, no lost-delta, left as-is.)
  `ResourceCrudTest` extended: partial decrement lands DB-truth quantity + existing floor-at-0 case.
  Pint + Larastan green locally; DB tests on CI.
- ✅ `2026-07-04` — **Hardened the `/errors` crash-intake endpoint** (gap analysis T2). The
  unauthenticated `POST /errors` had no throttle and unbounded table growth. Added an
  `inventory-errors` rate limiter (keyed device_id+IP, `INVENTORY_RL_ERRORS_DEVICE`, default 20/min)
  applied via `throttle:` middleware, plus `inventory:client-errors:prune` deleting
  `inventory_client_errors` rows older than `client_errors_retention_days`
  (`INVENTORY_CLIENT_ERRORS_RETENTION_DAYS`, default 30; 0 disables) — schedule it daily in the host
  app. `ClientErrorsTest` (5): valid store, 422 on junk, per-device 429, prune deletes-old/keeps-recent,
  prune no-op when disabled. Pint + Larastan green locally; DB tests on CI.
- ✅ `2026-07-04` — **Fixed reset-token expiry silently disabled under Carbon 3** (gap analysis T1).
  `ResetPasswordController` used `now()->diffInMinutes($created_at) > 60`; Carbon 3's `diffInMinutes`
  is signed, so a past `created_at` yields a negative value and the TTL check never fired — expired
  reset links stayed valid indefinitely. Replaced with an explicit instant comparison
  (`Carbon::parse($created_at)->isBefore(now()->subMinutes(TTL))`). `ResetPasswordTest` (3): valid
  token resets + revokes existing Sanctum tokens + consumes the row; expired (61-min) token rejected,
  password unchanged; tampered token rejected. Pint + Larastan green locally; DB tests on CI.
- ✅ `2026-07-04` — **`inventory:household:*` operator CLI family** (beyond create). Three
  console-only commands registered in `InventoryServiceProvider`: `inventory:household:list`
  (table of id / name / join code / member count via `withCount('users')`; graceful "No
  households yet." when empty), `inventory:household:add-member {household} {email*}` (attaches
  existing users by email — idempotent `syncWithoutDetaching`, warns+continues on unknown email,
  FAILURE on unknown household), and `inventory:household:regenerate-code {household}` (rotates
  the join code via `Household::generateUniqueJoinCode()`, prints old→new, FAILURE on unknown
  household). `HouseholdCommandsTest` (7): list-populated, list-empty, add-member attach,
  add-member idempotent+unknown-email warn, add-member unknown-household FAILURE, regenerate
  rotate, regenerate unknown-household FAILURE. Pint + Larastan green locally; DB tests on CI.
- ✅ `2026-07-04` — **Rate limiting / abuse protection** on the brute-forceable surfaces.
  Two named limiters registered in `InventoryServiceProvider::registerRateLimiters()`:
  `inventory-auth` on `register`/`login`/`google`/`forgot-password` (logout is token-bound, so
  exempt) and `inventory-join` on `households/join`. Auth layers a tight per-identity limit
  (submitted email + IP; falls back to IP when no email, e.g. `/auth/google`) under a looser
  per-IP cap (blunts distributed attempts without locking a shared NAT); join is keyed per
  authenticated user (code-guessing cap). All counts live in `config('inventory.rate_limits')`
  and are env-tunable (`INVENTORY_RL_AUTH_IDENTITY|AUTH_IP|JOIN_USER`); 0 disables a layer.
  `RateLimitTest` (4): per-identity 429, per-IP-catches-varied-emails 429, join per-user 429,
  and join-throttle-is-per-user-not-global. Closures read config per request so tests tighten
  limits at runtime. Pint + Larastan green locally; DB tests on CI (local PHP lacks pdo_sqlite).
  Also cleared pre-existing CI-red debt from the password-reset flow surfaced during this work:
  Pint reformatting on `ForgotPasswordController`/`ClientErrorController`/`ResetPasswordController`,
  and two `@phpstan-ignore argument.type` comments on `ResetPasswordController`'s package-view
  `view()` calls — matching the existing `LandingController` convention (the `inventory::`
  namespace is registered at runtime via `loadViewsFrom`, so it is unresolvable in package-only
  static analysis).
- ✅ `2026-06-23` — **Artisan CLI** (D-032) — `inventory:household:create {name} {--member=*}`
  creates a household with a fresh join code, optionally attaches existing users by email
  (warns + continues on unknown email), and prints the join code. Registered console-only.
  `CreateHouseholdCommandTest` (3). **Backend MVP complete.**
- ✅ `2026-06-23` — **Locations / shelves / products CRUD + stock actions** — nested
  apiResource routes under `/households/{household}` with **scoped route-model bindings**
  (each child verified ⊂ its parent) layered on `household.member`, so cross-household id
  manipulation 404s. `add`/`remove` (quantity floors at 0, row retained) + `move` (rejects a
  target shelf outside the household, 422). `Location`/`Shelf`/`Product` controllers, resources,
  and method-aware Form Requests; `Household::locations()` + `shelves()` (hasManyThrough) back
  the scoping. `ResourceCrudTest` (8) covers CRUD, stock floor, in-household move, cross-
  household move rejection, scoped-binding 404, and non-member 404. **MVP API surface complete.**
- ✅ `2026-06-23` — **Households + membership + search** — `EnsureHouseholdMember`
  (`household.member`) tenancy middleware (404, not 403, for non-members/out-of-tenant).
  `GET/POST /households`, `POST /households/join` (idempotent join-by-code, 404 on bad code),
  `GET /households/{household}/invite` (code + share link), `DELETE .../leave`, and
  `GET .../search?q=` (products with `location › shelf` path), per `specs/api-contract.md`.
  CSPRNG join codes (unambiguous alphabet) on `Household`. `HouseholdResource`/
  `SearchResultResource`, Store/Join form requests. `HouseholdTest` (8) + `SearchTest` (3)
  incl. tenancy isolation and auth-required. Pint/Larastan green locally; DB tests on CI.
- ✅ `2026-06-23` — **Auth security hardening** (from a commit security review): (1) the
  Google verifier now **fails closed** — with no configured client IDs it rejects all tokens
  instead of skipping the `aud` check (was: any Google token from any app would authenticate);
  (2) requires Google `email_verified`, closing the account-takeover-by-email-linking vector;
  (3) `google_id`/`avatar_url` removed from `$fillable` (set only from verified claims).
  Added `GoogleTokenInfoVerifierTest` (6 cases, `Http::fake`, runs locally).
- ✅ `2026-06-23` — **Auth (Sanctum + Google)** — `laravel/sanctum` added; `User` is now
  authenticatable with `HasApiTokens`. Endpoints `POST /api/v1/auth/register|login|google|
  logout` per `specs/api-contract.md`, with `RegisterRequest`/`LoginRequest` validation and
  a `UserResource`. Google sign-in verifies the Android **ID token** via a swappable
  `GoogleIdTokenVerifier` (default impl: Google `tokeninfo` endpoint, with issuer + optional
  `aud` checks) — chosen over Socialite, whose `userFromToken()` expects an OAuth *access*
  token, not the *ID* token Android Sign-In yields. Find-or-create matches on `google_id`
  then `email`. `AuthTest` (8 cases incl. duplicate-email, wrong-password, logout revocation,
  Google create-then-reuse, invalid-token) with a mocked verifier. Pint/Larastan green
  locally; DB tests on CI.
- ✅ `2026-06-23` — **Schema + models** — six `inventory_*` migrations (users, households,
  household_user pivot, storage_locations, shelves, products) per `specs/data-model.md`:
  prefixed tables, FK `cascadeOnDelete`, composite pivot PK, `enum` storage type, quantity
  default 0. Eloquent models (`User`, `Household`, `StorageLocation`, `Shelf`, `Product`) +
  `StorageType` enum + relationships + casts. `SchemaTest` covers tree build, membership,
  enum cast, quantity default, and cascade deletes (location→shelves→products, household→
  locations). Larastan/Pint green locally; DB tests run on CI (local PHP lacks pdo_sqlite).
- ✅ `2026-06-23` — **Package skeleton scaffolded** (`spdotdev/inventory`, mirrors
  scuttle-dev): auto-discovered `InventoryServiceProvider` (merges config, loads web +
  `api/v1` route groups, `inventory::` views, migrations dir; publishes config + assets),
  `config/inventory.php` (domain defaults to host `APP_URL`), `GET /` Frost "coming soon"
  landing page + `GET /api/v1/health` probe, testbench harness + 4 tests. Pint/Larastan/
  PHPUnit green locally and on CI. `composer.lock` pinned to PHP 8.3 (`config.platform`)
  so it installs on the 8.3 CI runner (local was 8.5 → had locked Symfony 8.1).
