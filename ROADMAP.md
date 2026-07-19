# Roadmap

> Forward-looking **commitments** only — the phased build plan and concrete TODOs being
> tracked now. Companion file [`BACKLOG.md`](BACKLOG.md) holds **wishes** (Ideas +
> brainstorm parking lot) and **history** (shipped milestones). Keeping commitments and
> wishes apart stops commitments from looking negotiable and ideas from looking promised.

Backend for the **Inventory** product, shipped as the Composer package `spdotdev/inventory`
mounted into the sd-admin host app. Authoritative spec lives in
[`docs/specs/`](docs/specs/); this file tracks build work.

Markers: 🟡 TBD · 🔲 TODO · 🛠 in progress · ✅ done (shipped work moves to `BACKLOG.md`).

---

## Phased plan

| Phase | Status | Scope |
|---|---|---|
| 0 — Package foundation | ✅ shipped 2026-06-23 | Skeleton (service provider, config, host-based route groups), landing page, `inventory_*` migrations + models, `household.member` middleware, versioned `/api/v1` skeleton. |
| 1 — Auth | ✅ shipped 2026-06-23 | Sanctum register/login/logout + Google sign-in (verify Android ID token). |
| 2 — MVP API | ✅ shipped 2026-06-23 | Households (create/list/invite/join/leave) + search; locations/shelves/products CRUD + add/remove/move. |
| 3 — CLI + polish | ✅ shipped 2026-06-24 | Artisan commands (household create, …); quality gates green (Pint/Larastan/PHPUnit). |
| 4 — Phase 2 | ✅ shipped 2026-07-10 | **Unlocked 2026-07-10** (user decision): web account/household UI ✅, `low_stock_threshold` ✅, Reverb live updates ✅, **production deploy (v0.1.5)** ✅. Household JSON export (API + web) + web live updates ✅ shipped **and deployed (v0.1.10)** 2026-07-11; further attributes stay 🟡 TBD. |
| 5 — Storage architecture editing (backend) | ✅ shipped 2026-07-13, deployed 2026-07-15 (v0.1.11) | Soft delete + client-minted `deletion_batch_id` + batch restore (undo), required delete *strategies* for a non-empty location/shelf (move/delete/unsort), manual drag `position` + bulk reorder for locations/shelves, the lazily-created per-location **Unsorted** system shelf, a `HouseholdPolicy@restructure` seam ahead of roles, per-product `is_starred`, and `inventory:deleted:prune` retention. Spec: `docs/superpowers/specs/2026-07-13-storage-architecture-editing-design.md`; plan: `docs/superpowers/plans/2026-07-13-storage-architecture-editing-backend.md`. Android UI shipped 2026-07-15 (v0.1.9 prerelease). |
| 6 — Household roles (Owner/Admin/Member) | ✅ shipped + deployed 2026-07-17 | `role` column on `inventory_household_user` + backfill; `HouseholdPolicy@restructure`/`manageMembers` role-gated (was "any member"); `manageMembers`/`transferOwnership`/`delete` capability gates; member promote/demote/remove + transfer-ownership on API + web. A household always has exactly one Owner. Spec: `docs/superpowers/specs/2026-07-17-household-roles-design.md`. |
| 7 — Web parity | ✅ shipped + deployed 2026-07-18 | Web app promoted to a first-class equal surface (full feature parity with barcode scanning as the one permanent app-only exception): storage-architecture editing (delete strategies, reorder, restore), roles/member management, household delete, and the "Recently deleted" browser (`Support\RecentlyDeleted`) landed on web. Spec: `docs/superpowers/specs/2026-07-18-web-parity-design.md`. |
| 8 — GAP audit waves 4-8 + 2026-07-19 bug audit | ✅ shipped, prod on v0.1.21 | Iterative parity/stability audits (see `BACKLOG.md` → Done for the full list) plus the 2026-07-19 comprehensive bug audit: an N+1 in `HouseholdResource::roleOf()` and a missing upper bound on shelf `position` (same class as the earlier W14 product-quantity fix). The API/Android twin of "Recently deleted" — `GET .../households/{household}/deleted` — shipped the same day, closing the one real Android parity gap `ROADMAP.md`/android had open. |

Detailed build order: [`CLAUDE.md`](CLAUDE.md) → "Build order" and
[`docs/backend-plan.md`](docs/backend-plan.md).

---

## Active TODOs

> **Backend complete and live in production** (`inventory.scuttle.dev`, v0.1.10,
> 2026-07-11) — MVP + Phase 2 all shipped and CI-green. See [`BACKLOG.md`](BACKLOG.md)
> → Done for history. Open items below are decision- or dependency-gated.

### PHASE 2 (unlocked 2026-07-10 — user decision; was deferred 2026-07-04)
- [x] **`low_stock_threshold` product attribute** — shipped 2026-07-10 (2ea1534).
  Nullable unsigned int on `inventory_products` (null = off, floor 1); validated in
  ProductRequest, exposed in ProductResource; `/api/v1` backward compatible. The
  Android "running low" dashboard tile shipped the same day.
- [x] **Web account/household UI** — shipped 2026-07-10 (stages 1+2, per the same-day
  scoping decisions: thin Blade + web routes, onboarding + full inventory CRUD).
  `inventory` session guard on `inventory_users`; /login + /register; /app/households
  (create/join/invite code+link/members/leave); locations/shelves/products CRUD with
  the same atomic stock actions as the API (extracted to Product::addStock/removeStock)
  and identical tenancy (member-gated, 404 never 403; scoped bindings). `/api/v1`
  untouched. Follow-ups: QR on the invite page shipped 2026-07-10 (bacon/bacon-qr-code,
  inline SVG via Support\InviteQr). Global product search shipped 2026-07-10 —
  `/app/households/{household}/search`, the Blade twin of the API SearchController
  (same tenancy gate, LIKE escaping via the new shared `Support\Like`, 50-row bound),
  results linking into location pages. Still open: Google sign-in on the web
  (needs a GCP redirect-flow client + secret — external config).
  **Prod click-through smoke-passed 2026-07-10** (browser against
  `inventory.scuttle.dev`, throwaway account, data cleaned up after): register →
  auto-login → create household → invite code/link/QR render → add location/shelf/
  product → stock stepper → edit page saves `low_stock_threshold` + mandatory and
  the "running low" badge appears → search finds the product with its
  `location › shelf` path → leave household deletes the tree → the household URL
  then 404s (tenancy intact) → sign out.
- [x] **Deployed to production 2026-07-10** (user decision) — tagged **v0.1.5** and bumped
  sd-admin's lock from v0.1.0 (sd-admin 5df2444 → CI → auto-deploy to d051). Verified live:
  `/up` 200 (DB-probing health check), `/login` + `/register` 200 (web UI routes new in
  this release), API auth guard intact. Production had been on v0.1.0 since the MVP —
  this picked up ~60 commits of fixes and Phase 2.

- [x] **Live updates backend (Q-3, user decision 2026-07-10: full Reverb)** — shipped
  2026-07-10. `HouseholdChanged` broadcast (model observers → every surface pings),
  private `inventory.household.{id}` channel, Sanctum-gated `/api/v1/broadcasting/auth`.
  Host side: Reverb container + nginx websocket proxying live in sd-admin.
  **Server config completed 2026-07-10** (user-approved SSH): `REVERB_*` keys +
  `BROADCAST_CONNECTION=reverb` set on d051; verified 101 Switching Protocols
  through Caddy→nginx→Reverb and a broadcast job processed clean. Two prod
  gotchas fixed on the way: the nginx catch-all block needed `default_server`
  (unmatched hosts fell to the crm block), and the single-file bind mount served
  stale config after deploys (now a directory mount).
  **Full loop smoke-verified end-to-end 2026-07-10** from a real external websocket
  client against prod: connect → Sanctum-gated channel auth → subscribe to
  `private-inventory.household.{id}` → API product create → `household.changed`
  received on the socket ~1.6 s after the mutation. Only the Android UI's reaction
  to the ping remains covered by unit tests rather than a live device.

- [x] **Redesign the landing page** — shipped 2026-07-11 (spec:
  `docs/superpowers/specs/2026-07-11-landing-page-redesign-design.md`). Hybrid
  marketing-first one-pager: hero + CSS phone mockups (no images/JS), feature grid,
  how-it-works, honest "private preview / coming to Google Play" band (no download
  link), CTAs into the web UI, EN + NL via `inventory::landing.*` +
  `Accept-Language` negotiation (landing only). **Deployed 2026-07-11** as
  v0.1.8 (sd-admin lock bump 7328887, user-approved); verified live — EN + NL
  negotiation, `Vary: Accept-Language`, /up + /login 200.

### PHASE 5 — storage architecture editing, backend (2026-07-13, spec-driven)
- [x] **Soft delete + batch restore (undo)** — shipped 2026-07-13 (`93d8b75`, review-fix
  `04a9bb6`). Locations/shelves/products gain `deleted_at` + `deletion_batch_id`;
  `ON DELETE CASCADE` FKs are kept but a soft delete is an `UPDATE` and never fires
  them. `deletion_batch_id` is **client-minted** so the client (which alone knows
  whether several deletes are one gesture) can undo it as a unit via
  `POST .../restore/{batch}` (`989a601`, broadcast-coverage `43461a1`, review-fix
  `49b98da` — refuses with **409** when a restored row's parent is still dead under a
  different batch, never a misleading 200 or a leaky 404).
- [x] **`HouseholdPolicy@restructure` seam** — shipped 2026-07-13 (`0cdf472`). Every
  structural mutation (create/update/delete/reorder on locations & shelves) now
  authorizes through this one policy method. Today it grants any member — "all
  members are equal" still holds in practice — but it exists so Owner/Admin/Member
  roles, when decided, are a change to this one method body, not every call site.
- [x] **Manual reorder** — shipped 2026-07-13 (`740e727`, review-fix `cbdd79b`).
  `position` on locations (shelves already had it); `PATCH .../locations/reorder` and
  `PATCH .../locations/{l}/shelves/reorder` take a **complete** ordered id list and
  rewrite `position` in one all-or-nothing transaction — a partial list is a 422, not
  a silent partial reorder.
- [x] **Delete strategies + the Unsorted system shelf** — shipped 2026-07-13
  (Unsorted shelf `d2abb3d`, review-fix `e86c509`; shelf-delete strategy `a693cd9`,
  review-fix `fcd4b15`; location-delete strategy `26995cc`, review-fix `60e8596`; web
  UI parity `e59f446`, `cde6b66`). Deleting a location/shelf that still holds
  something now REQUIRES an explicit strategy (`move_contents`/`delete_contents` for
  locations; `move_products`/`unsort_products`/`delete_products` for shelves) — the
  server never guesses. `unsort_products` lands on a lazily-created, per-location
  **Unsorted** shelf (`is_system: true`): unrenameable, unmovable, excluded from
  reorder, always sorted last.
- [x] **`inventory:deleted:prune`** — shipped 2026-07-13 (`ae26982`, review-fix
  `2f9ff83`). Hard-deletes soft-deleted rows (and reclaims their orphaned product
  images) past `INVENTORY_DELETED_RETENTION_DAYS` (default 30; `0` disables) —
  schedule it in the host app, same pattern as `inventory:client-errors:prune`.
- [x] **Product `is_starred`** — shipped 2026-07-13 (`d847929`). A plain user-toggled
  favorite/pin flag; no server-side sort/filter semantics, storage + passthrough only.
- [x] **Docs reconciled to the above** — this task (2026-07-13). `CLAUDE.md`'s
  "no soft deletes" / "no roles" LOCKED rules rewritten to match; `docs/specs/`
  gained the new columns/endpoints; config + command docs picked up
  `deleted_retention_days` / `inventory:deleted:prune`.

**Was deliberately out of scope here** (by the design doc, `docs/superpowers/specs/2026-07-13-storage-architecture-editing-design.md`):
all Android-side work — nav rework, edit mode, tabs⇄list toggle, collapsible groups,
the household edit page, the delete-strategy dialog as rendered — tracked separately
in the `inventory-android` roadmap. Both the Android UI and the production deploy of
this backend work have since shipped — see the phased plan table above.

### REMAINING (need a decision or external dependency — not autonomous)
- [x] **Google sign-in on the web UI** — shipped 2026-07-11 (14d28eb, tagged v0.1.9;
  spec: `docs/superpowers/specs/2026-07-11-web-google-signin-design.md`). Server-side
  authorization-code flow, no Socialite/JS: /auth/google → Google consent → callback
  verifies the exchanged id_token through the existing GoogleTokenInfoVerifier (web
  client id added to the aud allowlist) and links via the extracted
  Auth\GoogleAccountLinker (shared with the API flow). Fail-closed on
  `INVENTORY_GOOGLE_WEB_CLIENT_ID/SECRET`. GCP: dedicated "Inventory Web" client
  (758637503304-6q4tf85…), redirect URI `https://inventory.scuttle.dev/auth/google/callback`.
  **Deployed 2026-07-11** (sd-admin 7215f59 → v0.1.9 live) with the env keys set on
  d051; verified on prod: /auth/google 302s to Google with the exact registered
  redirect_uri, forged callback state bounces to /login (no 500), and the button
  renders on /login + /register.

### QUALITY
- [x] **CI live and green** — ci (Pint/Larastan/PHPUnit), audit, secret-scan all pass on
  the skeleton commit. `composer.lock` pinned to PHP 8.3 (`config.platform`) so it installs
  on the CI runner. Pre-push hook available via `make install-hooks`.
