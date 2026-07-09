# PROJECT BRIEF & DECISION LOG

> **Single source of truth.** Nothing is final until marked `LOCKED`.

---

## METADATA
| Field | Value |
|---|---|
| **Project codename** | _TBD_ (working: Household Inventory) |
| **Studio** | Scuttle Development (KvK 96040947 · VAT NL005184584B85 · Eindhoven, NL) |
| **Operator** | Stanislav Plotnikov |
| **Created / updated** | 2026-06-14 |
| **Status** | `SPEC COMPLETE` — only Q-3 (realtime mechanism) advisory-open |
| **Backend** | `LOCKED` — headless server-authoritative Laravel API + DB. No web UI. |
| **Client** | `LOCKED` — Android (Kotlin/Compose) sole client. |
| **Design** | `LOCKED` — B · Frost (frosted glass, icy-blue, rounded, light/dark). |
| **Nature** | `LOCKED` — private, non-commercial app for now. |

---

## PRODUCT SUMMARY
Multi-user, multi-household **inventory** management for home storage. A household holds one or more **storage locations** (freezer / fridge / pantry / other); each has shelves; each shelf holds products tracked as name + quantity. Members share a live picture to know current stock, avoid duplicate buying, and find where an item is stored. Headless Laravel API + DB, Android client. Pure inventory — no expiry, recipes, or shopping list.

---

## DECISION LOG
| # | Decision | Status |
|---|---|---|
| D-002 | Household is the tenant boundary; all data belongs to a household. | `LOCKED` |
| D-003 | User ↔ Household many-to-many via pivot (`household_user`). | `LOCKED` |
| D-004 | Server-authoritative Laravel API mandatory. | `LOCKED` |
| D-005 | Web part = headless API + DB only; Android is sole client. | `LOCKED` |
| D-006 | Always-online. No offline store / sync / conflict resolution. | `LOCKED` |
| D-007 | Active household via explicit route param `/api/v1/households/{household}/...`. | `LOCKED` |
| D-008 | Realtime: pull-to-refresh + optimistic UI; WebSockets deferred. | `RECOMMENDED` (Q-3) |
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
| D-022 | Settings screen: theme (System/Light/Dark), household mgmt, account/sign out. Rounded controls. | `LOCKED` |
| D-023 | Navigation: Household → Storage overview → Shelves (tabs) → Products. | `LOCKED` |
| D-024 | Global product search across the household; results show location (location › shelf) + quantity. MVP. | `LOCKED` |
| D-025 | **Generalise `freezers` → `storage_locations` with `type` (freezer / fridge / pantry / other).** | `LOCKED` |
| D-026 | **Invite via join code + shareable/copyable link + QR** (link encodes the code; QR encodes the link). | `LOCKED` |

---

## FUNCTIONAL SCOPE
### MVP — locked
- Multi-user accounts; Sanctum token auth.
- Users in multiple households; invite via **join code, shareable link, or QR**; leave self.
- CRUD: **Storage locations** (type: freezer / fridge / pantry / other).
- CRUD: **Shelves** (within a location).
- CRUD: **Products** (name + quantity; on a shelf).
- **Browse storage** overview (locations with type + shelf/item counts).
- **Search products** across the household; results show location (location › shelf) + quantity.
- **Add / remove quantity**; quantity 0 = out of stock (row retained).
- **Relocate** product between shelves/locations.
- **Settings**: theme (System/Light/Dark), household management, account / sign out.
- Always-online; shared; last-write-wins.

### Phase 2 — candidates
- Product attributes: unit, category, barcode.
- Barcode scanning (native).
- Filter / sort.
- Backup / restore / export.

### Out of scope
- Expiry tracking + reminders, recipes, shopping list, offline mode, web/iOS UI (now), soft deletes, permissions/roles, actor/audit log, GDPR machinery (until non-private).

---

## DATA MODEL
```
users             (id, email, password_hash, name, created_at)
households        (id, name, join_code, created_at)              -- join_code drives invite link + QR
household_user    (household_id, user_id, joined_at)             -- composite PK; no role
storage_locations (id, household_id, name, type[freezer|fridge|pantry|other], created_at)  -- FK ON DELETE CASCADE
shelves           (id, location_id, name, position, created_at)  -- FK ON DELETE CASCADE
products          (id, shelf_id, name, quantity, created_at, updated_at)  -- FK ON DELETE CASCADE; qty 0 = out of stock
personal_access_tokens (Sanctum)
```
**Tenancy:** member-check middleware on `/api/v1/households/{household}/*`; child queries scoped by validated `household_id`.

---

## API SCOPING (LOCKED)
```
POST   /api/v1/auth/login                         -> Sanctum token
POST   /api/v1/auth/logout                        -> revoke
GET    /api/v1/households
POST   /api/v1/households
GET    /api/v1/households/{household}/invite       -> { code, link }   (client renders QR from link)
POST   /api/v1/households/join        { code }     -> join by code
DELETE /api/v1/households/{household}/leave        -> self
GET    /api/v1/households/{household}/search?q=    -> products + location (location › shelf)
       /api/v1/households/{household}/locations[/{location}]                   CRUD
       /api/v1/households/{household}/locations/{location}/shelves[/{shelf}]   CRUD
       /api/v1/households/{household}/shelves/{shelf}/products[/{product}]     CRUD
POST   .../products/{product}/add      { amount }  -> increment
POST   .../products/{product}/remove   { amount }  -> decrement (floor 0)
POST   .../products/{product}/move     { shelf_id }-> relocate within household
```
Middleware: `auth:sanctum` -> `household.member({household})` -> resource access.

---

## DESIGN (B · Frost)
- Frosted-glass cards, icy-blue accent (#7dd3fc), rounded controls throughout, Plus Jakarta Sans.
- Full light / dark, switched in-app via Settings (System / Light / Dark).
- Screens: Storage overview · Shelves (tab strip + swipe) · Search · Invite (code/link/QR) · Settings.
- Reference mock: frost-app.html (+ frost-dark.png / frost-light.png).

---

## COMPLIANCE POSTURE
- **GDPR:** `DEFERRED` — private app. Re-trigger mandatory before any public/non-trusted/commercial release.
- **NIS2 / DORA:** `N/A`.

---

## TECH DIRECTION
- **Backend:** PHP/Laravel modular monolith, headless API, Sanctum. `LOCKED`.
- **Data:** PostgreSQL or MySQL + Redis. _Pick at infra step._
- **Android:** Kotlin · Compose · MVVM/MVI · single-activity · Hilt · Retrofit/OkHttp · no Room (always-online) · QR via client lib.
- **Hosting:** DigitalOcean, EU (AMS3/FRA1), doctl/Terraform IaC.

---

## OPEN QUESTIONS
- **Q-3:** Live cross-user push, or pull-to-refresh? (Recommendation: pull-to-refresh — no WebSockets.)

---

## CHANGELOG
- `2026-06-14` — Init through full spec: tenancy, headless API + Android, always-online, versioning, Sanctum, exclusions, simplified inventory model (add/remove, no expiry, no soft delete, last-write-wins, no permissions), GDPR deferred, relocate, shelves-as-tabs, Frost design, settings, navigation hierarchy, search (MVP).
- `2026-06-14` — Q-5 resolved: generalise to storage_locations + type (D-025). Q-4 resolved: invite via code + link + QR (D-026). Model/API/scope updated. Mock rebuilt to 5 screens. Only Q-3 remains (advisory).
