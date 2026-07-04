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
