# Product Description — Inventory
### Scuttle Development · created 2026-06-14 · updated 2026-06-23 · status: spec complete · private app

> Reconciled to [`project-brief.md`](./project-brief.md) (the decision log). Where this
> narrative and the brief ever disagree, the brief wins.

---

## 1. Executive Summary
A multi-user, multi-household **inventory** system for home storage. Members of a
household share a single live picture of what is stored, where, and how much — so
nobody double-buys, nobody hunts through drawers, and everyone knows current stock at
a glance. It is **general-purpose**: a freezer, a fridge, or a pantry are simply
*examples* of storage locations it can track.

It ships as a **headless Laravel API + database** — packaged as a Composer library
mounted into a host Laravel app — consumed by a **native Android client**,
always-online, with the server as the single source of truth. Scope is deliberately
pure inventory: no expiry tracking, no recipes, no shopping list. Private,
non-commercial for now.

**Value in one line:** a shared, always-current answer to "what do we have, and how much?"

---

## 2. Problem & Opportunity
In a shared household, no single person holds the current state of what's in storage.
The result is duplicate purchases, buried stock, and constant "do we still have…?"
friction. This product removes that by giving every member the same live inventory.

| Pain | Impact | Removed by |
|---|---|---|
| Duplicate buying | Wasted spend | One shared, live inventory across members |
| "Do we have it?" guesswork | Time + friction | Per-shelf product + quantity, visible to all |
| Stock drift between members | Disputes, errors | Server-authoritative single source of truth |

The product is about **visibility and coordination**, not expiry/waste management —
expiry is explicitly out of scope.

---

## 3. Target Users
- **Primary:** households of 2+ sharing storage — couples, families, flatmates. Private, trusted users.
- A user may belong to **multiple households** (own home, partner's, parents') — first-class, not an edge case.
- Access: always-online, mobile-first, opportunistic (at the freezer, at the shop).

---

## 4. Domain Model
```
Household  ──< Storage location (freezer|fridge|pantry|other)  ──< Shelf  ──< Product (name + quantity)
   │
   └──< Members (users; all equal, no roles)
```
- **Household** — sharing boundary and unit of ownership. Everything belongs to a household, never to an individual.
- **Member** — a user who joined a household. Many-to-many; all members equal (no permissions).
- **Storage location / Shelf** — physical location hierarchy. Hard delete cascades downward (irreversible).
- **Product** — an item on a shelf, tracked as **name + quantity**. Quantity 0 = out of stock (kept for easy re-add). No expiry, no batches.

Tenancy is enforced at the API boundary: every request is scoped to a household the
caller is verifiably a member of. Canonical schema → [`../specs/data-model.md`](../specs/data-model.md).

---

## 5. Functional Scope

### 5.1 MVP — locked
- Multi-user accounts; **email/password + Google** sign-in; token auth (Sanctum).
- Multiple households per user; **invite via join code, link, or QR**; leave self.
- CRUD storage locations, shelves, products (name + quantity).
- **Browse** storage overview; **search** products across the household.
- **Add / remove quantity**; out-of-stock at 0 (row retained).
- **Relocate** a product between shelves/locations within a household.
- **Settings** (theme, household management, account/sign out).
- **Marketing landing page** at `inventory.{domain}`.
- Always-online; shared, last-write-wins consistency.

### 5.2 Phase 2 — candidates (not committed)
- Web/admin (Filament) UI in the host app.
- Product attributes: unit, category, barcode; barcode scanning.
- Filter / sort; backup / restore / export.
- Live updates (Reverb) if demanded.

### 5.3 Explicitly out of scope
- Expiry tracking + reminders, recipes, shopping list.
- Offline mode, iOS UI (now), soft deletes, roles/permissions, audit log.
- GDPR machinery — deferred while private; **mandatory re-review before any public/commercial release**.

---

## 6. Architecture & Delivery (and why)
| Decision | Choice | Rationale |
|---|---|---|
| Backend | Headless Laravel API + DB, shipped as a **Composer package** mounted into a host app (sd-admin) | Reuses existing host infra/deploy; lowest TCO; server is single source of truth |
| Routing | Host-based subdomain `inventory.{domain}` | Clean separation; landing page at `/`, API at `/api/v1` |
| Client | Native Android (Kotlin/Compose), sole client | Matches reference product + usage |
| Connectivity | Always-online | Deletes the offline-sync subsystem entirely |
| Multi-tenancy | Household in the URL, membership enforced at boundary | Stateless, explicit, client-agnostic |
| API versioning | `/api/v1` from day one | Protects phones running older builds |
| Auth | Own users; Sanctum tokens; email/password + Google (Socialite) | First-party mobile; one identity model, two sign-in methods |
| Database | MySQL on the host connection; `inventory_` table prefix | Matches sd-admin; avoids table collisions |
| Concurrency | Last-write-wins | Low household concurrency; accepted, no locking overhead |
| Real-time | Pull-to-refresh + optimistic UI; WebSockets deferred | Live infra premature at this scale |
| Hosting | Rides host app (DigitalOcean, EU) | Cost + data locality; no separate deploy |

API-first means a future web/iOS client is an additive option on the same contract,
not a second project.

---

## 7. Non-Functional Requirements
- **Security:** TLS 1.2+, input validation at boundaries, secrets via env only, tenancy enforced by middleware before resource access.
- **Compliance:** GDPR deferred (private app); re-triggers on any non-private release. NIS2/DORA N/A.
- **Availability:** API is the critical path (always-online); managed MySQL + host app on DO.
- **Performance:** read-heavy, low-concurrency per household; trivially served at expected scale. Index `household_id`/`shelf_id`; FULLTEXT on `products.name` only if search needs it.
- **Maintainability:** explicit over magic, SRP, document the *why*.

---

## 8. Success Metrics
- Reduction in duplicate purchases (headline value).
- Active households + members per household (collaboration is the core value).
- Products tracked and stock operations per household.
- Member retention and multi-household adoption.

---

## 9. Suggested Phasing
1. **Package foundation:** `spdotdev/inventory` skeleton (service provider, config, host-based route group), landing page, schema + migrations (`inventory_` prefix), own auth (email/password + Google), Sanctum, household membership + join/leave, tenancy middleware, versioned API skeleton, Artisan CLI.
2. **MVP:** location/shelf/product CRUD + add/remove/relocate + search; Android client (auth + Google, household switcher, CRUD + stock actions, always-online, Frost UI).
3. **Phase 2:** Filament admin, unit/category/barcode + scanning, filter/sort, backup.
4. **Optional later:** web/iOS client on the same API; live updates (Reverb) if demanded.

---

## 10. Open Decisions
| Ref | Question | Affects |
|---|---|---|
| Q-3 | Live push or pull-to-refresh? (trending: pull-to-refresh) | WebSockets vs none |

*(Q-6 resolved 2026-06-23: domain defaults to the host app's own `APP_URL` domain, overridable via `INVENTORY_DOMAIN`.)*

*Source of truth for decisions: [`project-brief.md`](./project-brief.md).*
