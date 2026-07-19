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

> **Shipped 2026-07-10** as the Phase-2 web UI (thin Blade: /login + /register,
> household onboarding, full inventory CRUD, invite QR, global search) — see
> [`ROADMAP.md`](ROADMAP.md) → Phase 2. Kept for the original rationale below;
> still open from it: Google sign-in on the web (external GCP config).

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

> **Deferred 2026-07-04** (backlog-sweep decision), then **unlocked 2026-07-10** (user
> decision) — now a commitment tracked in [`ROADMAP.md`](ROADMAP.md) → Phase 2. The
> scoping questions above (web session guard vs token, thin web layer vs Filament)
> are the first work item; the headless `/api/v1` contract stays untouched.

---

## Ideas — parking lot
- 💡 Web account/profile settings page (name/email/password change), thin wrapper over
  the same `ProfileController`/`UpdateProfileRequest`/`UpdatePasswordRequest` the API
  uses. Added 2026-07-19 — the API-side self-service endpoints shipped this pass, but
  no `resources/views/web` page calls them yet, which is a real gap against the
  2026-07-18 web-parity decision ("full feature parity"), not a permanent app-only
  exception like barcode scanning.
- 💡 Filament admin resources for households/locations/products (Phase 2 web UI).
  *Deferred 2026-07-04, unlocked 2026-07-10 (user decision) — folded into the web
  account/household UI commitment in [`ROADMAP.md`](ROADMAP.md) → Phase 2.*
- 💡 Codename / brand for the product (product-level; METADATA still lists "Inventory"
  as the working title).
- 💡 Public/commercial release path — would re-trigger the deferred GDPR work (D-018).

---

## Done
- ✅ `2026-07-19` — **Admin `deleteUser` now heals the single-Owner invariant.**
  Gap audit: `AdminController::deleteUser` relied solely on the pivot's
  `cascadeOnDelete` and never ran the "household needs at least one Owner"
  logic that `HouseholdController::leave()` enforces (it 409s a sole Owner
  rather than let them leave ownerless) — deleting a household's sole Owner (or
  last member) through `/api/v1/admin/*` could leave a household with zero
  owners, or an orphaned zero-member household that's never cleaned up. Fixed:
  for each household the deleted user owned, promote the earliest-joined
  remaining member to Owner, or delete the household outright if they were the
  sole member (mirrors `leave()`'s last-member cleanup). Two new regression
  tests (`test_deleting_the_sole_owner_promotes_another_member`,
  `test_deleting_the_sole_owner_of_a_single_member_household_deletes_it`); all
  403 tests + Pint + Larastan green. (The audit's other two findings —
  `deleteHousehold` hard-deleting vs. the soft-delete/batch posture, and
  `ShelfRequest`'s unscoped `location_id` validation — turned out to be
  non-issues: `HouseholdController::destroy()` itself hard-deletes households
  too, so admin is already consistent, and the `ShelfRequest` gap is an
  already-documented, deliberate tradeoff — a Form Request can't see the
  household to scope a `Rule::exists` against, and the controller enforces it.)
- ✅ `2026-07-19` — **Fixed a fail-open in the restore-permission fix, same day it
  shipped** (caught by post-commit security review). `Restorer::batchOwnerId()`
  only reads `deleted_by` off rows where it's non-null, so a batch soft-deleted
  BEFORE the `deleted_by` column existed (any pre-existing production row)
  resolves to a null owner — but `RestoreController`/`WebRestoreController` were
  skipping `Gate::authorize` entirely whenever the owner was null, on the
  (wrong) assumption that meant the batch didn't exist. It meant any Member
  could restore any household's legacy batch, restructure permission or not.
  Added `Restorer::batchExists()` (existence check with no `deleted_by` filter)
  and gate on THAT instead — `restoreBatch` already requires `restructure` when
  the owner is null, so a legacy batch is safe once actually gated. New test
  `test_a_member_cannot_restore_a_legacy_batch_with_no_deleted_by` (simulates a
  pre-migration row via a direct query-builder update, bypassing the API so
  `deleted_by` is never stamped) confirms a Member gets 403 and the Owner still
  restores it fine. 401 tests, Pint/Larastan green.
- ✅ `2026-07-19` — **A Member can restore their own soft-delete.** Restoring a batch
  (`RestoreController`/`WebRestoreController`) was gated unconditionally on
  `Gate::authorize('restructure', $household)`, Owner/Admin only — but a plain Member
  can soft-delete a product with no restructure grant at all (`ProductController::
  destroy`), so a Member who deleted something by mistake had no way to undo their own
  action. Added a nullable `deleted_by` column (mirrors `deletion_batch_id`) to
  `inventory_storage_locations`/`inventory_shelves`/`inventory_products`, stamped
  everywhere `deletion_batch_id` already is — `HierarchyDeleter::deleteShelf`/
  `deleteLocation` (new `$deletedBy` parameter, threaded through every API + web
  controller caller) and the product batch-of-one mints in `ProductController`/
  `WebProductController`. `Restorer::batchOwnerId()` reads it off any one row of a
  batch (locations, shelves, or products — whichever the batch touched). New
  `HouseholdPolicy::restoreBatch(User, Household, ?int $batchOwnerId)`: true if
  `restructure()` is true OR the caller IS the batch owner. Both restore controllers
  now look up the batch owner first and only invoke the gate when one was found — an
  unknown/already-purged/already-restored batch (`$batchOwnerId === null`) falls
  straight through to the existing `STATUS_NOTHING`/409 path instead of a 403, so
  probing a guessed batch id can't distinguish "never existed" from "existed but isn't
  yours" (matches the 403-vs-404 posture in `HouseholdPolicy`'s class docblock).
  `WebProductController::destroy`'s undo-flash gate was flipped from `restructure` to
  `restoreBatch` too — it was hiding the Undo button from the very Member who just
  deleted their own product (audit #8's original fix hid the button instead of letting
  the restore work). Owner/Admin restore of any batch is unchanged. Tests: extended
  `RestoreTest`/`WebRestoreTest` (Member restores their own batch, Member is refused
  someone else's batch with a 403, Owner still restores a Member's batch, unknown-batch
  probing stays a 409 not a 403) and `RestructureGateCoverageTest::test_restore_is_gated`
  (now plants a real owned batch so the `restoreBatch` gate is actually exercised, since
  an unknown batch no longer reaches the gate at all). **Left as-is (noted, not
  fixed)**: `resources/views/web/household.blade.php`'s "Recently deleted" section is
  still wrapped in `@can('restructure', $household)`, so a Member still can't see or use
  that page-level restore list for their own batches — only the inline undo-toast flash
  works for them today. A full per-batch `can_restore` field on the batch-list resource
  would fix it but is more than this fix strictly needs; flagged here for whoever picks
  up the "Recently deleted" UI next.
- ✅ `2026-07-19` — **Admin listing pagination + self-service account management** (fresh
  audit, 2 medium gaps). `AdminController::listUsers`/`listHouseholds` ran unbounded
  `->get()` over the whole table; capped both at 50 with `->limit(50)`, matching the
  existing convention already used by `searchUsers`/`SearchController`/
  `WebSearchController` rather than introducing pagination metadata as a new shape.
  Separately, no endpoint let an authenticated user manage their own account — only the
  enumeration-safe forgot-password email flow existed. Added `ProfileController`
  (`GET/PATCH /api/v1/me`, `PATCH /api/v1/me/password`) behind `auth:sanctum`, with
  `UpdateProfileRequest` (name/email, email uniqueness excludes self, same
  lowercase-normalization as `RegisterRequest`/`LoginRequest`) and
  `UpdatePasswordRequest` (requires `current_password` verified via `Hash::check`
  before the new password is accepted; the `User::password` `hashed` cast hashes it on
  assignment). Tests: `AdminApiTest` (pagination cap on both endpoints) + new
  `ProfileTest` (update own name/email, resubmitting own unchanged email, rejecting
  another user's email, 401 unauthenticated, correct/wrong current-password on password
  change). **Web-side scoped out**: no self-service account/profile page exists yet in
  `resources/views/web`; per the 2026-07-18 web-parity decision this is a real gap
  against "full feature parity," logged as an idea below rather than done silently.
- ✅ `2026-07-19` — **Admin API rate limiting + stale docblock.** `/api/v1/admin/*` (the
  static-bearer-token surface) was the only credential/abuse-prone route group in
  `routes/api.php` with no throttle at all — auth/join/errors all had one. Added a new
  `inventory-admin` limiter (`InventoryServiceProvider::registerRateLimiters`, IP-keyed
  since the bearer token has no per-identity concept), config knob
  `inventory.rate_limits.admin_per_ip` (env `INVENTORY_RL_ADMIN_IP`, default 60/min),
  applied via `throttle:inventory-admin` alongside the existing `inventory.admin`
  middleware. Test: `RateLimitTest::test_admin_api_throttles_per_ip`. Also fixed
  `HouseholdPolicy::delete()`'s stale docblock claiming it was "not yet wired to a
  route" — it has been since `HouseholdController::destroy()`.
- ✅ `2026-07-11` — **Live updates on the web UI.** The Blade household + location pages
  now subscribe to the same private `inventory.household.{id}` Reverb channel as the
  Android client, via a dependency-free vanilla Pusher-protocol `<script>` partial
  (`web/partials/live-updates`): handshake → session-authenticated channel auth →
  debounced `location.reload()` on the `household.changed` ping (thin server-rendered
  views — re-rendering IS the re-fetch). New session-guarded `POST /broadcasting/auth`
  (web middleware + `auth:inventory`) beside the Sanctum-gated api/v1 one; the channel
  callback already allowed the `inventory` guard. Renders nothing when no broadcaster
  is configured, matching the server side's graceful no-op. Tests: member/stranger/
  guest web channel auth, client embedded with a broadcaster, absent without one.
  **Deployed 2026-07-11 as v0.1.10** (sd-admin c159ee7 → CI → deploy, user-authorized);
  verified live: health ok, web+API export routes and /broadcasting/auth all answer
  with their auth boundaries (302/401), not 404.
- ✅ `2026-07-11` — **Household JSON export (the Phase-2 "backup/export" TBD).** One
  versioned document (`inventory.household-export.v1`): household meta (no join code —
  it's a credential and exports leave the household), member list, full locations →
  shelves → products tree. Shared `Support\HouseholdExport` builder behind both
  `GET /api/v1/households/{household}/export` (member-gated, same group as invite) and
  `GET /app/households/{household}/export` (web twin + "Your data" download card on the
  household page). Pretty-printed attachment download. Tests: API + web member download,
  non-member 404 both surfaces, guest redirect, join-code-absent (141 suite green).
  **Deployed 2026-07-11 as v0.1.10** (same release as the web live updates above).
- ✅ `2026-07-04` — **Removed the fail-open `dependency-review` audit job** (wave-3 X6). The wave-3 audit
  flagged that `audit.yml`'s `dependency-review` job lacked the Android job's `continue-on-error` and red-ed
  every `composer.*` PR (Dependency Graph isn't enabled). The obvious "mirror Android" fix would have made it
  *fail-open* — silently passing on real CVEs. Instead removed the job entirely: `composer audit --locked` in
  the same workflow is already the blocking dependency-CVE gate, so the GitHub-native action was pure
  infra-red with no added coverage. A fail-open gate is worse than no duplicate gate.
- ✅ `2026-07-04` — **MCP `SearchUsersTool` escapes LIKE wildcards** (wave-3 X9). The exact injection W11
  fixed in `AdminController::searchUsers` survived in the MCP admin tool: a raw `%`/`_` in the query acted as
  a wildcard. Applied the same `str_replace(['!','%','_'], …) + LIKE ? ESCAPE '!'` treatment to both the name
  and email clauses. (`Mcp/` is excluded from the phpstan/test job since `laravel/mcp` is a suggested dep;
  fix verified by inspection against the W11 pattern.)
- ✅ `2026-07-04` — **Household product search bounded at 50** (wave-3 X10). `SearchController` returned every
  match — and an empty `q` dumped the whole catalog — unlike the admin and MCP searches, which both
  `limit(50)`. Added `->limit(50)` for parity. Test seeds 60 matching products and asserts exactly 50 come
  back. Pint+Larastan green.
- ✅ `2026-07-04` — **`add-member` CLI matches email case-insensitively** (wave-3 X11). W13 normalized web
  auth to lowercase (emails stored lowercase), but `inventory:household:add-member` looked up the raw
  argument — so on case-sensitive SQLite `add-member 1 Foo@x.com` silently added nobody for a `foo@x.com`
  user. Now `Str::lower()` the argument before lookup. Test adds a member via a mixed-case argument. Pint+Larastan green.
- ✅ `2026-07-04` — **`add()` clamps quantity at the cap (was overflow-able)** (wave-3 X5). W14 capped the
  per-request `amount` and the stored `quantity` (create/update) at 1,000,000, but `add()` used
  `increment()` with no ceiling on the resulting total — repeated adds from a near-cap quantity could
  exceed the `unsignedInteger` column (SQLSTATE 22003 → 500) and always violated the "≤ 1,000,000"
  invariant. Rewrote `add()` as an atomic clamped update (`CASE WHEN quantity + N > MAX THEN MAX ELSE
  quantity + N END`, portable, mirroring `remove()`'s floor). Test asserts the total pins at the cap. Pint+Larastan green.
- ✅ `2026-07-04` — **Password-reset link built on `inventory.domain`, not `APP_URL`** (wave-3 X3).
  `ForgotPasswordController` did `url(route('inventory.reset-password', …, absolute: false))`, prefixing the
  path with `config('app.url')` and discarding the route's own domain. `/reset-password` is only registered
  on the inventory domain, so on a supported split-domain deploy (`INVENTORY_DOMAIN` ≠ host `APP_URL`) the
  emailed link pointed at the host app → 404. Now build `https://` + `config('inventory.domain')` + the
  relative path, mirroring `HouseholdController::invite()`. Test asserts the link host. Pint+Larastan green.
- ✅ `2026-07-04` — **Backend edge-path tests + orphaned-image cleanup** (wave-2 W15). Added: stock `amount`
  0/negative/missing rejected on both add + remove (min:1); Google-only account rejected on password login
  and mixed-case register→login (added under W12/W13). **Orphaned-image decision:** a direct product
  `destroy()` now best-effort deletes the stored image (test asserts the file is gone), while cascade
  deletes (shelf/location/household) are DB-level (ON DELETE CASCADE, no Eloquent event) and *intentionally*
  leave the file to the disk's lifecycle — documented in `destroy()` since app-level tree deletion is what
  the hard-delete-cascade rule deliberately avoids. Pint+Larastan green.
- ✅ `2026-07-04` — **Stock `amount`/`quantity` capped to prevent UNSIGNED overflow 500** (wave-2 W14).
  `amount` was `min:1` with no `max` and `quantity` `min:0` with no `max`; a large/typo'd or repeated add
  could push the `unsignedInteger` column past ~4.29B and throw MySQL "out of range" (500). Added a shared
  `ProductRequest::MAX_QUANTITY` (1,000,000) cap on both `quantity` (create/update) and the add/remove
  `amount`, so an over-cap value is a clean 422. Test asserts the cap rejects on add + remove. Pint+Larastan green.
- ✅ `2026-07-04` — **Email normalized to lowercase consistently across auth flows** (wave-2 W13).
  Register/login stored+looked up verbatim; forgot-password lowercased; Google matched verbatim — masked
  on MySQL (CI collation) but broken on the SQLite the package is CI-tested on, so register `Foo@x.com`
  then login/reset with other casing silently missed. Normalize to lowercase once at the boundary
  (`prepareForValidation()` in Register + Login requests, and lowercase the Google claim). Test covers
  mixed-case register→login. Pint+Larastan green.
- ✅ `2026-07-04` — **Login no longer leaks account existence via timing** (wave-2 W12). On a missing email
  (or a Google-only, passwordless account) `login()` threw before any `Hash::check`, so non-existent
  accounts responded measurably faster than wrong-password ones — a user-enumeration oracle inconsistent
  with the non-enumerable forgot-password + 404-everywhere posture. Now always run one `Hash::check`
  against the real hash or a constant bcrypt dummy (default cost), so both paths do equal work before the
  same `auth.failed`. Tests: unknown email and passwordless account both 422. Pint+Larastan green.
- ✅ `2026-07-04` — **LIKE wildcards escaped in product + user search** (wave-2 W11). `SearchController`
  and `AdminController::searchUsers` interpolated the raw term into `%…%`, so a user-typed `%`/`_` acted
  as a wildcard (`50%` over-matched; a lone `%` returned everything). Bound params meant no injection, just
  wrong results. Escape `!`,`%`,`_` (escape-char first) and match with an explicit `LIKE ? ESCAPE '!'` —
  portable because SQLite (the fast CI job) doesn't treat backslash as a LIKE escape by default, unlike
  MySQL, so `addcslashes`+default-escape would have silently diverged between the two CI jobs. Test asserts
  `%` is literal. Pint+Larastan green; DB test on CI.
- ✅ `2026-07-04` — **Last member leaving deletes the household + its tree** (wave-2 W6). `leave()`
  unconditionally detached; when the last member left, the household and its whole location→shelf→product
  tree survived with zero members — unreachable by anyone (tenancy 404s non-members), dead data that only
  grows, inconsistent with the hard-delete posture. After detach, if `users()->count() === 0`, delete the
  household (ON DELETE CASCADE cleans the tree). Tests cover both last-member-leave (tree gone) and
  members-remaining (household kept). Pint+Larastan green.
- ✅ `2026-07-04` — **Shelves get an increasing `position` on create** (wave-2 W5). The client sends only
  `name`, so `ShelfController::store` never computed `position` → every shelf landed at the model default
  0, and `index()`'s `orderBy('position')` left the Shelves tab/pager order undefined (could reshuffle
  between loads). Default `position` to `max(position)+1` within the location (0 for the first) when the
  request omits it. Test asserts sequential creates get strictly increasing positions. Pint+Larastan green.
- ✅ `2026-07-04` — **Invite `/join/{code}` link now resolves to a real page** (wave-2 W2).
  `HouseholdController::invite()` advertised `https://{domain}/join/{code}`, but `routes/web.php`
  only registered `/` and `/reset-password` — so any recipient opening the invite in a browser
  got a hard 404 at the exact moment onboarding matters. Added `JoinController` + a Frost-styled
  `join.blade.php` (shows the code, optional "Get the app" via new `INVENTORY_ANDROID_APP_URL`,
  `noindex`) wired at `Route::get('/join/{code}')->name('inventory.join')`. Test asserts the page
  renders the code. Pint + Larastan green; DB/web test on CI.
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
