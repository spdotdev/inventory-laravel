# PROJECT BRIEF & DECISION LOG

> **Single source of truth for decisions.** Nothing is final until marked `LOCKED`.
> Canonical schema → [`../specs/data-model.md`](../specs/data-model.md).
> Canonical API → [`../specs/api-contract.md`](../specs/api-contract.md).

---

## METADATA
| Field | Value |
|---|---|
| **Project** | Inventory (general-purpose household stock manager) |
| **Studio** | Scuttle Development (KvK 96040947 · VAT NL005184584B85 · Eindhoven, NL) |
| **Operator** | Stanislav Plotnikov |
| **Created** | 2026-06-14 · **Updated** 2026-07-10 |
| **Status** | `SHIPPED` — MVP + Phase 2 live in production (`inventory.scuttle.dev`, 2026-07-10) |
| **Backend** | `LOCKED` — headless server-authoritative Laravel API, shipped as a **Composer package** mounted into a host app (sd-admin). |
| **Client** | `LOCKED` — Android (Kotlin/Compose) sole client. |
| **Design** | `LOCKED` — B · Frost (frosted glass, icy-blue, rounded, light/dark). |
| **Nature** | `LOCKED` — private, non-commercial app for now. |

---

## PRODUCT SUMMARY
Multi-user, multi-household **inventory** manager for home storage. The product is
**general-purpose** — freezer, fridge, and pantry are *examples* of what it tracks,
not its identity. A household holds one or more **storage locations** (freezer /
fridge / pantry / other); each has shelves; each shelf holds products tracked as
name + quantity. Members share a live picture to know current stock, avoid duplicate
buying, and find where an item is stored. Headless Laravel API + DB, Android client.
Pure inventory — no expiry, recipes, or shopping list.

---

## ARCHITECTURE (2026-06-23 pivot)
`inventory-laravel` is **not a standalone application** — it is a **Laravel package**
(`spdotdev/inventory`) installed via Composer into a host Laravel app (**sd-admin**),
mirroring the `spdotdev/scuttle-dev` pattern:
- Auto-discovered `InventoryServiceProvider`; namespace `Spdotdev\Inventory\`.
- **Host-based routing:** answers on `config('inventory.domain')`, which **defaults to the host app's own domain** (parsed from `APP_URL`) and is overridable via `INVENTORY_DOMAIN` (e.g. `inventory.scuttle.dev`).
- `/` serves a **marketing "coming soon" landing page**; `/api/v1/*` is the headless API.
- Ships **its own auth** (`inventory_users`) — email/password **and** Google sign-in,
  issuing Sanctum tokens. Independent of the host app's users.
- Ships **migrations** onto the host's **MySQL** connection; all package tables are
  prefixed `inventory_` to avoid collisions with host tables.
- Ships **Artisan commands** for admin tasks (e.g. create a household).
- Deploys as part of the host app (sd-admin on DigitalOcean d051) — no separate deploy.

---

## DECISION LOG
| # | Decision | Status |
|---|---|---|
| D-002 | Household is the tenant boundary; all data belongs to a household. | `LOCKED` |
| D-003 | User ↔ Household many-to-many via pivot (`inventory_household_user`). | `LOCKED` |
| D-004 | Server-authoritative Laravel API mandatory. | `LOCKED` |
| D-005 | ~~Standalone headless app; Android sole client.~~ **Superseded by D-027** — backend is a Composer package; Android is still the sole client. | `SUPERSEDED` |
| D-006 | Always-online. No offline store / sync / conflict resolution. | `LOCKED` |
| D-007 | Active household via explicit route param `/api/v1/households/{household}/...`, served under host-based subdomain `inventory.{domain}`. | `LOCKED` |
| D-008 | ~~Realtime: pull-to-refresh + optimistic UI; WebSockets deferred.~~ **Superseded by D-034** — pull-to-refresh remains the fallback. | `SUPERSEDED` |
| D-009 | API versioning from day one (`/api/v1/`). | `LOCKED` |
| D-010 | Auth: Laravel Sanctum, token-based. | `LOCKED` |
| D-011 | Shopping list and recipes excluded. | `LOCKED` |
| D-012 | Stock ops = add / remove quantity; quantity 0 = out of stock (row retained). | `LOCKED` |
| D-013 | No expiry date. Product = name + quantity, one row each. | `LOCKED` |
| D-014 | No actor stamping / activity log. | `LOCKED` |
| D-015 | No soft deletes. Hard delete, ON DELETE CASCADE (location→shelves→products). | `LOCKED` |
| D-016 | Concurrency = last-write-wins. | `LOCKED` |
| D-017 | No role-based permissions; all members equal. | `LOCKED` |
| D-018 | GDPR deferred (private app); re-triggers before any public/commercial release. | `LOCKED` |
| D-019 | Relocate — move a product between shelves/locations within a household. | `LOCKED` |
| D-020 | Shelves render as scrollable tab strip + swipe paging (`ScrollableTabRow` + `HorizontalPager`); add targets active shelf. | `LOCKED` |
| D-021 | Design direction = B · Frost. | `LOCKED` |
| D-022 | Settings screen: theme (System/Light/Dark), household mgmt, account/sign out. | `LOCKED` |
| D-023 | Navigation: Household → Storage overview → Shelves (tabs) → Products. | `LOCKED` |
| D-024 | Global product search across the household; results show location (location › shelf) + quantity. MVP. | `LOCKED` |
| D-025 | Generalise `freezers` → `storage_locations` with `type` (freezer / fridge / pantry / other). | `LOCKED` |
| D-026 | Invite via join code + shareable/copyable link + QR (link encodes the code; QR encodes the link). | `LOCKED` |
| **D-027** | **Backend ships as a Composer package (`spdotdev/inventory`) mounted into a host Laravel app (sd-admin), not a standalone app.** | `LOCKED` |
| **D-028** | **Host-based routing via `config('inventory.domain')`, defaulting to the host app's own domain (`APP_URL`) and overridable with `INVENTORY_DOMAIN`; `/` = marketing landing page, `/api/v1` = headless API.** | `LOCKED` |
| **D-029** | **Package ships its own auth (`inventory_users`): email/password + Google sign-in (Socialite), issuing Sanctum tokens. Independent of host users.** | `LOCKED` |
| **D-030** | **All package-owned tables prefixed `inventory_` to avoid host-table collisions.** | `LOCKED` |
| **D-031** | **Database engine = MySQL on the host app's default connection (matches sd-admin). No separate DB.** | `LOCKED` |
| **D-032** | **Ship Artisan CLI commands for admin tasks (e.g. `inventory:household:create`).** | `LOCKED` |
| **D-033** | **Global product naming: "Inventory" identity; freezer/fridge/pantry are examples, not the brand.** | `LOCKED` |
| **D-034** | **Live updates via Laravel Reverb (Q-3 resolved 2026-07-10, user decision): model observers broadcast `household.changed` on private `inventory.household.{id}` channels; Sanctum-gated `/api/v1/broadcasting/auth`; clients keep pull-to-refresh as fallback.** | `LOCKED` |
| **D-035** | **Phase 2 web surface (unlocked 2026-07-10, user decision): session-guarded thin-Blade account/household/inventory UI on the same domain (`/login`, `/register`, `/app/*`). Additive only — never a breaking change to `/api/v1`.** | `LOCKED` |

---

## FUNCTIONAL SCOPE
### MVP — locked
- Multi-user accounts; **email/password + Google** sign-in; Sanctum token auth.
- Users in multiple households; invite via **join code, shareable link, or QR**; leave self.
- CRUD: **Storage locations** (type: freezer / fridge / pantry / other).
- CRUD: **Shelves** (within a location).
- CRUD: **Products** (name + quantity; on a shelf).
- **Browse storage** overview (locations with type + shelf/item counts).
- **Search products** across the household; results show location (location › shelf) + quantity.
- **Add / remove quantity**; quantity 0 = out of stock (row retained).
- **Relocate** product between shelves/locations.
- **Settings**: theme (System/Light/Dark), household management, account / sign out.
- **Marketing landing page** at `inventory.{domain}` root ("coming soon"; modern/professional;
  hints an Android inventory-management app without disclosing detail).
- **Artisan CLI** for admin tasks (create household, etc.).
- Always-online; shared; last-write-wins.

### Phase 2 — unlocked 2026-07-10 (user decision), largely shipped
- ✅ Web account/household UI (D-035 — thin Blade, session guard; shipped 2026-07-10).
- ✅ Barcode scanning (Android CameraX + ML Kit; `code` field) — shipped 2026-07-10.
- ✅ Low-stock threshold + "running low" view — shipped 2026-07-10.
- ✅ Live cross-user updates via Reverb (D-034) — shipped 2026-07-10.
- Remaining candidates: unit/category product attributes; backup / restore / export.

### Out of scope
- Expiry tracking + reminders, recipes, shopping list, offline mode, iOS UI (now),
  soft deletes, permissions/roles, actor/audit log, GDPR machinery (until non-private).

---

## DATA MODEL
Canonical: [`../specs/data-model.md`](../specs/data-model.md). Summary: `inventory_users`,
`inventory_households`, `inventory_household_user`, `inventory_storage_locations`,
`inventory_shelves`, `inventory_products`, plus Sanctum `personal_access_tokens`.
All FKs `ON DELETE CASCADE`; `quantity` floors at 0; MySQL host connection.

## API
Canonical: [`../specs/api-contract.md`](../specs/api-contract.md). REST+JSON under
`https://inventory.{domain}/api/v1`, Sanctum bearer tokens, middleware
`auth:sanctum → household.member → resource policy`.

---

## DESIGN (B · Frost)
- Frosted-glass cards, icy-blue accent (#7dd3fc), rounded controls, Plus Jakarta Sans.
- Full light / dark, switched in-app via Settings (System / Light / Dark).
- App screens: Storage overview · Shelves (tab strip + swipe) · Search · Invite
  (code/link/QR) · Settings · Auth (email/password + Google).
- The web landing page reuses the Frost palette for brand consistency.
- Reference mock: `frost-app.html` + `frost-dark.png` / `frost-light.png`, committed to
  [`inventory-android/docs/design/`](https://github.com/spdotdev/inventory-android/tree/main/docs/design).
  The HTML is an interactive 5-screen prototype (Storage · Search · Shelves · Invite · Settings)
  with a working light/dark toggle.

---

## TECH DIRECTION
- **Backend:** PHP 8.3+, Laravel 13, Composer **package** (orchestra/testbench for dev),
  Sanctum + Socialite (Google), headless API + landing page. `LOCKED`.
- **Data:** MySQL (host connection) + Redis (host cache/queue).
- **Android:** Kotlin · Compose · MVVM/MVI · single-activity · Hilt · Retrofit/OkHttp ·
  no Room (always-online) · native Google Sign-In · QR via client lib.
- **Hosting:** rides the host app (sd-admin) on DigitalOcean, EU (AMS3/FRA1).

---

## COMPLIANCE POSTURE
- **GDPR:** `DEFERRED` — private app. Re-trigger mandatory before any public/commercial release.
- **NIS2 / DORA:** `N/A`.

---

## OPEN QUESTIONS
_None._
- ~~**Q-3:** Live cross-user push, or pull-to-refresh?~~ **Resolved 2026-07-10 (user
  decision):** full Reverb live updates (D-034); pull-to-refresh stays as fallback.
- ~~**Q-6:** Which parent domain for `inventory.{domain}`?~~ **Resolved 2026-06-23:** the package
  defaults to the **host app's own domain** (`APP_URL` host); a dedicated subdomain like
  `inventory.scuttle.dev` is opt-in via `INVENTORY_DOMAIN` — production uses it.

---

## CHANGELOG
- `2026-06-14` — Init through full spec: tenancy, headless API + Android, always-online,
  versioning, Sanctum, exclusions, simplified inventory model, GDPR deferred, relocate,
  shelves-as-tabs, Frost design, settings, navigation, search (MVP). Q-4/Q-5 resolved
  (D-025 storage locations, D-026 invite code+link+QR).
- `2026-06-23` — **Architecture pivot:** backend becomes a Composer package mounted into
  sd-admin via host-based routing on `inventory.{domain}` (D-027/D-028). Added own-auth with
  email/password + Google (D-029), table prefixing (D-030), MySQL host DB (D-031), Artisan
  CLI (D-032), global "Inventory" naming (D-033), and a marketing landing page. D-005
  superseded. Schema + API extracted to `specs/`.
- `2026-06-23` — Q-6 resolved: `inventory.domain` defaults to the host app's own domain
  (`APP_URL`), overridable via `INVENTORY_DOMAIN`. Frost mocks (`frost-app.html`,
  `frost-dark.png`, `frost-light.png`) added to `inventory-android/docs/design/`.
- `2026-07-10` — **Phase 2 unlocked (user decision) and shipped; production go-live.**
  Web account/household UI (D-035), Reverb live updates (D-034, Q-3 resolved, D-008
  superseded), `low_stock_threshold`, barcode scanning + household color/icon on
  Android. Backend v0.1.5 deployed to production via sd-admin (d051) at
  `inventory.scuttle.dev`; Reverb configured and verified end-to-end.
