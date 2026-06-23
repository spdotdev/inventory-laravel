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

---

## Ideas — parking lot
- 💡 Filament admin resources for households/locations/products (Phase 2 web UI).
- 💡 Rate-limiting + abuse protection on auth + join-by-code endpoints.
- 💡 `inventory:household:*` CLI family (add member, regenerate join code, list).

---

## Done
- ✅ `2026-06-23` — **Package skeleton scaffolded** (`spdotdev/inventory`, mirrors
  scuttle-dev): auto-discovered `InventoryServiceProvider` (merges config, loads web +
  `api/v1` route groups, `inventory::` views, migrations dir; publishes config + assets),
  `config/inventory.php` (domain defaults to host `APP_URL`), `GET /` Frost "coming soon"
  landing page + `GET /api/v1/health` probe, testbench harness + 4 tests. Pint/Larastan/
  PHPUnit green locally and on CI. `composer.lock` pinned to PHP 8.3 (`config.platform`)
  so it installs on the 8.3 CI runner (local was 8.5 → had locked Symfony 8.1).
