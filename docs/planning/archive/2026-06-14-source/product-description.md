# Product Description — Household Freezer Inventory
### Working title · prepared by Scuttle Development · 2026-06-14 · status: scoping · private app

---

## 1. Executive Summary
A multi-user, multi-household freezer **inventory** system. Members of a household share a single live picture of what is stored, where, and how much — so nobody double-buys, nobody hunts through drawers, and everyone knows current stock at a glance.

It ships as a **headless Laravel API + database** consumed by a **native Android client**, always-online, with the server as the single source of truth. Scope is deliberately pure inventory: no expiry tracking, no recipes, no shopping list. This is a private, non-commercial app for now.

**Value in one line:** a shared, always-current answer to "what do we have, and how much?"

---

## 2. Problem & Opportunity
In a shared household, no single person holds the current state of the freezer. The result is duplicate purchases, buried stock, and constant "do we still have…?" friction. This product removes that by giving every member the same live inventory.

| Pain | Impact | Removed by |
|---|---|---|
| Duplicate buying | Wasted spend | One shared, live inventory across members |
| "Do we have it?" guesswork | Time + friction | Per-shelf product + quantity, visible to all |
| Stock drift between members | Disputes, errors | Server-authoritative single source of truth |

The product is about **visibility and coordination**, not expiry/waste management — expiry is explicitly out of scope.

---

## 3. Target Users
- **Primary:** households of 2+ sharing a freezer — couples, families, flatmates. Private, trusted users.
- A user may belong to **multiple households** (own home, partner's, parents') — first-class, not an edge case.
- Access: always-online, mobile-first, opportunistic (at the freezer, at the shop).

---

## 4. Domain Model
```
Household  ──< Freezer  ──< Shelf  ──< Product (name + quantity)
   │
   └──< Members (users; all equal, no roles)
```
- **Household** — sharing boundary and unit of ownership. Everything belongs to a household, never to an individual.
- **Member** — a user who joined a household. Many-to-many; all members equal (no permissions).
- **Freezer / Shelf** — physical location hierarchy. Hard delete cascades downward (irreversible).
- **Product** — an item on a shelf, tracked as **name + quantity**. Quantity 0 = out of stock (kept for easy re-add). No expiry, no batches.

Tenancy is enforced at the API boundary: every request is scoped to a household the caller is verifiably a member of.

---

## 5. Functional Scope

### 5.1 MVP — locked
- Multi-user accounts; token auth (Sanctum).
- Multiple households per user; join via code; leave self.
- CRUD Freezers, Shelves, Products (name + quantity).
- **Add / remove quantity**; out-of-stock at 0 (row retained).
- **Relocate** a product between shelves/freezers within a household.
- Always-online; shared, last-write-wins consistency.

### 5.2 Phase 2 — candidates (not committed)
- Product attributes: unit, category, barcode.
- Barcode scanning.
- Search / filter / sort.
- Backup / restore / export.

### 5.3 Explicitly out of scope
- Expiry tracking + reminders, recipes, shopping list.
- Offline mode, web/iOS UI (now), soft deletes, roles/permissions, audit log.
- GDPR machinery — deferred while private; **mandatory re-review before any public/commercial release**.

---

## 6. Architecture & Delivery (and why)
| Decision | Choice | Rationale |
|---|---|---|
| Backend | Headless Laravel API + DB, modular monolith | Lowest TCO; server is single source of truth |
| Client | Native Android (Kotlin/Compose), sole client | Matches reference product + usage |
| Connectivity | Always-online | Deletes the offline-sync subsystem entirely |
| Multi-tenancy | Household in the URL, membership enforced at boundary | Stateless, explicit, client-agnostic |
| API versioning | `/api/v1` from day one | Protects phones running older builds |
| Auth | Sanctum, token-based | Right-sized for first-party mobile |
| Concurrency | Last-write-wins | Low household concurrency; accepted, no locking overhead |
| Real-time | Pull-to-refresh + optimistic UI; WebSockets deferred | Live infra premature at this scale |
| Hosting | DigitalOcean, EU (AMS3/FRA1), IaC | Cost + data locality |

API-first means a future web/iOS client is an additive option on the same contract, not a second project.

---

## 7. Non-Functional Requirements
- **Security:** TLS 1.2+, input validation at boundaries, secrets via env only, tenancy enforced by middleware before resource access.
- **Compliance:** GDPR deferred (private app); re-triggers on any non-private release. NIS2/DORA N/A.
- **Availability:** API is the critical path (always-online); managed DB + redundant app instances on DO.
- **Performance:** read-heavy, low-concurrency per household; trivially served by a monolith + Redis.
- **Maintainability:** explicit over magic, SRP, document the *why*.

---

## 8. Success Metrics
- Reduction in duplicate purchases (headline value).
- Active households + members per household (collaboration is the core value).
- Products tracked and stock operations per household.
- Member retention and multi-household adoption.

---

## 9. Suggested Phasing
1. **Foundation:** schema + migrations, Sanctum auth, household membership + join/leave, tenancy middleware, versioned API skeleton.
2. **MVP:** Freezer/Shelf/Product CRUD + add/remove/relocate; Android client (auth, household switcher, CRUD + stock actions, always-online).
3. **Phase 2:** unit/category/barcode + scanning, search/filter, backup.
4. **Optional later:** web/iOS client on the same API; storage-location generalisation; live updates (Reverb) if demanded.

---

## 10. Open Decisions
| Ref | Question | Affects |
|---|---|---|
| Q-3 | Live push or pull-to-refresh? (trending: pull-to-refresh) | WebSockets vs none |
| Q-4 | Invite via join code (recommended), email, or both? | Onboarding flow |
| Q-5 | Generalise "freezer" → "storage location" now? | Domain model breadth |

---

*Source of truth for decisions: project-brief.md (decision log).*
