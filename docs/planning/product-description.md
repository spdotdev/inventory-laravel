# Product Description — Inventory
### Scuttle Development · created 2026-06-14 · updated 2026-07-10 · status: shipped, live in production · private app

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
mounted into a host Laravel app — consumed by a **native Android client** (with a
companion **web account/household UI** on the same domain), always-online, with the
server as the single source of truth. Live changes broadcast to all members in real
time (Reverb). Scope is deliberately pure inventory: no expiry tracking, no recipes,
no shopping list. Private, non-commercial for now.

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

### 5.2 Phase 2 — unlocked 2026-07-10, largely shipped
- ✅ Web account/household UI in the host app (thin Blade, session guard).
- ✅ Barcode scanning (Android) + product `code`; ✅ low-stock threshold; ✅ filter/sort.
- ✅ Live updates (Reverb) with pull-to-refresh fallback.
- Remaining candidates: unit/category attributes; backup / restore / export.

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
| Real-time | Reverb websockets (`household.changed` per-household channel), plus pull-to-refresh + optimistic UI as fallback | Shared households want a live picture; Reverb rides the existing host stack |
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
3. **Phase 2** *(shipped 2026-07-10)*: web account/household UI, barcode + scanning,
   low-stock, filter/sort, live updates (Reverb).
4. **Optional later:** iOS client on the same API; unit/category attributes; backup/export.

---

## 10. Open Decisions
_None._

*(Q-3 resolved 2026-07-10: full Reverb live updates, pull-to-refresh as fallback.
Q-6 resolved 2026-06-23: domain defaults to the host app's own `APP_URL` domain,
overridable via `INVENTORY_DOMAIN` — production serves `inventory.scuttle.dev`.)*

*Source of truth for decisions: [`project-brief.md`](./project-brief.md).*
