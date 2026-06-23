# Roadmap

> Forward-looking **commitments** only — the phased build plan and concrete TODOs being
> tracked now. Companion file [`BACKLOG.md`](BACKLOG.md) holds **wishes** (Ideas +
> brainstorm parking lot) and **history** (shipped milestones). Keeping commitments and
> wishes apart stops commitments from looking negotiable and ideas from looking promised.

Backend for the **Inventory** product, shipped as the Composer package `spdotdev/inventory`
mounted into the sd-admin host app. Authoritative spec lives in
[`inventory-docs`](https://github.com/spdotdev/inventory-docs); this file tracks build work.

Markers: 🟡 TBD · 🔲 TODO · 🛠 in progress · ✅ done (shipped work moves to `BACKLOG.md`).

---

## Phased plan

| Phase | Status | Scope |
|---|---|---|
| 0 — Package foundation | 🔲 TODO | Skeleton (service provider, config, host-based route groups), landing page, `inventory_*` migrations + models, `household.member` middleware, versioned `/api/v1` skeleton. |
| 1 — Auth | 🔲 TODO | Sanctum register/login/logout + Google sign-in (Socialite, verify Android ID token). |
| 2 — MVP API | 🔲 TODO | Households (create/list/invite/join/leave) + search; locations/shelves/products CRUD + add/remove/move. |
| 3 — CLI + polish | 🔲 TODO | Artisan commands (household create, …); quality gates green (Pint/Larastan/PHPUnit). |
| 4 — Phase 2 | 🟡 TBD | Filament admin UI; product attributes (unit/category/barcode); backup/export. |

Detailed build order: [`CLAUDE.md`](CLAUDE.md) → "Build order" and
[`docs/backend-plan.md`](docs/backend-plan.md).

---

## Active TODOs

> Foundation skeleton + initial landing page shipped 2026-06-23 — see
> [`BACKLOG.md`](BACKLOG.md) → Done. Next up: the `inventory_*` schema + models, then auth.

### AUTH (next)
- [ ] **Auth** — add `laravel/sanctum` + `laravel/socialite`; register/login/logout + Google
  (verify the Android-supplied Google ID token); issue Sanctum tokens. `inventory_users` is
  already migrated; this adds `HasApiTokens` + the auth endpoints per `specs/api-contract.md`.

### LANDING PAGE
- [ ] **Redesign the landing page** — once the product has something to show, replace the
  "coming soon" placeholder with a proper marketing page (value prop, screenshots/mockups,
  Play Store link). Tracked now so it isn't forgotten; depends on the initial page + app
  store presence. Brainstorm the web account/household-management angle first
  (see [`BACKLOG.md`](BACKLOG.md) → Ideas).

### QUALITY
- [x] **CI live and green** — ci (Pint/Larastan/PHPUnit), audit, secret-scan all pass on
  the skeleton commit. `composer.lock` pinned to PHP 8.3 (`config.platform`) so it installs
  on the CI runner. Pre-push hook available via `make install-hooks`.
