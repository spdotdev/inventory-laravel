# Storage architecture editing — design

**Date:** 2026-07-13
**Status:** Approved, ready for planning
**Spans:** `inventory-laravel` (API + schema), `inventory-android` (UI)
**Follow-on:** Spec 2 — Roles & permissions (deferred, see [Deferred](#deferred-to-spec-2))

## Problem

A tester reported that nothing in the storage hierarchy can be renamed: not a household,
not a storage location, not a shelf. Investigating that turned up a larger and more
dangerous gap.

**What is missing:**

- No rename/edit for household, location, or shelf. (Products are fully editable.)
- No way to reorder shelves — `shelves.position` exists in the schema and is never written.
- No coherent place to *design* a storage layout; creation is scattered across three
  screens (a FAB on StorageOverview, a `+` in the LocationDetail app bar).

**What is dangerous:** every foreign key in the hierarchy is `cascadeOnDelete()`
(`inventory_storage_locations`, `inventory_shelves`, `inventory_products`), and the
Android shelf delete is a bulk "delete mode" with **no confirmation dialog at all**
(`ShelvesViewModel.kt:99`). Deleting a location today silently hard-deletes its shelves
and every product on them. There is no undo, no soft delete, and no record.

The rename gap is the ticket. The delete behaviour is the bug.

## Decisions

Settled during design; do not relitigate without a reason.

| # | Decision | Rationale |
|---|---|---|
| D1 | Editing lives **in place**, on the screens that already show the hierarchy — not in Settings. | `AllStorages`/`StorageOverview` *is* the household→location tree; `LocationDetail` *is* the shelf list. A Settings-hosted tree would be a second navigation hierarchy that can disagree with the first, and would make fixing a visible typo require leaving the screen it is on. |
| D2 | **One edit mode**, entered by a pencil in the top bar, serving both jobs: tapping a row body opens its **edit sheet** (repair a typo), while checkboxes and drag handles handle **restructuring** (bulk delete, reorder). | Distinct tap targets keep "select or open?" unambiguous. Putting delete behind an explicit mode is what makes it hard to trigger by accident — which is the whole problem today. |
| D3 | **Manual (drag) order always wins.** A star is a marker and a filter — never a sort. | Shelf order is *physical*. If starring a shelf floated it to the top, the list would stop matching the fridge the user is standing in front of. |
| D4 | Deleting a non-empty container **always asks what to do with the contents**, and always confirms. | Currently it just destroys them. |
| D5 | Deletes are **soft** (`deleted_at`) with snackbar undo; rows purge after 30 days. | The cheapest possible insurance against "I couldn't know what scenario would happen". |
| D6 | Every edit affordance is gated behind **one** permission seam, `canRestructure(household)`, which returns `true` for all members today. | Roles land in Spec 2 and must not require touching a hundred call sites. |
| D7 | Households are **tenants**, but the app stays **cross-tenant**: Dashboard aggregates, Storage groups by household. No workspace switcher. | A user with their own home + their parents' house wants to see both at once. |
| D8 | Household **order and collapse state are device-local**; location/shelf order is **server-side**. | Physical structure is shared reality. "Which of my households do I care about" is a personal view preference. |

## Scope

**In:** rename/edit for household, location, shelf · drag-reorder for locations and shelves ·
unified edit mode with multi-select delete · delete-with-contents choice · soft delete +
undo · the "Unsorted" shelf · bottom-nav rework · shelf list/tab view toggle · collapsible
household groups · household edit page · stars on products · the `canRestructure` seam.

**Out:** roles & permissions (Spec 2) · a user-facing trash/restore UI · drag-and-drop
reparenting *in the UI* (the API supports reparenting; nothing exposes it as a gesture) ·
manual drag-order for **products** (they already have a filter/sort control — adding a
third ordering concept would fight it) · cross-household moves.

---

## Backend (`inventory-laravel`)

### Schema

New migrations. Nothing existing is dropped.

| Table | Change | Why |
|---|---|---|
| `inventory_storage_locations` | `+ position` (unsigned int, default 0) | Drag-reorder. Shelves already have this; locations do not. |
| `inventory_storage_locations` | `+ deleted_at` (nullable ts, indexed) | Soft delete. |
| `inventory_storage_locations` | `+ is_system` (bool, default false) | Reserved; see note under [Unsorted](#the-unsorted-shelf). |
| `inventory_shelves` | `+ deleted_at` (nullable ts, indexed) | Soft delete. |
| `inventory_shelves` | `+ is_system` (bool, default false) | Marks the Unsorted shelf. |
| `inventory_products` | `+ deleted_at` (nullable ts, indexed) | Soft delete. |
| `inventory_products` | `+ is_starred` (bool, default false) | Stars on products (D3 — marker/filter, not a sort). |
| all three | `+ deletion_batch_id` (nullable uuid, indexed) | See [Undo](#soft-delete-and-undo). |

Models get Laravel's `SoftDeletes` trait. **Note:** the existing `cascadeOnDelete()` foreign
keys do *not* fire on a soft delete (it is an `UPDATE`, not a `DELETE`), so cascading is
done in application code — deliberately, because each level needs the user's chosen
strategy. The FK cascade remains correct for the eventual hard purge.

### Reordering

`PATCH /households/{h}/locations/reorder` and `PATCH .../locations/{l}/shelves/reorder`,
each taking an ordered array of ids and rewriting `position` in one transaction. A single
bulk endpoint, not N individual PATCHes — a drag produces one write, and a partial failure
must not leave the list half-sorted.

### Reparenting

`PATCH .../shelves/{s}` gains a writable **`location_id`**. This is required by the
location-delete "move contents" strategy (moving a location's contents *is* reparenting its
shelves). No UI gesture exposes it in this spec; the endpoint exists so that a future
drag-between-locations feature is a client change, not a migration.

### Delete with a strategy

Delete becomes a `DELETE` with a required `strategy` (and, where relevant, a target id).
The server rejects a delete of a non-empty container with no strategy — the client must
have asked.

**`DELETE .../shelves/{s}`**

| `strategy` | Effect |
|---|---|
| `move_products` + `target_shelf_id` | Products reassigned to the target shelf; shelf soft-deleted. |
| `unsort_products` | Products reassigned to this location's Unsorted shelf (created on demand); shelf soft-deleted. |
| `delete_products` | Products soft-deleted in the same batch; shelf soft-deleted. |
| *(omitted)* | Allowed only if the shelf is empty. Otherwise `422`. |

**`DELETE .../locations/{l}`**

| `strategy` | Effect |
|---|---|
| `move_contents` + `target_location_id` | The location's shelves are **reparented** into the target location; location soft-deleted. |
| `delete_contents` | Shelves and products soft-deleted in the same batch; location soft-deleted. |
| *(omitted)* | Allowed only if the location has no shelves (or only an empty Unsorted). Otherwise `422`. |

There is deliberately **no `unsort` strategy at the location level**: "unsorted" means
*off-shelf but still in this location*, and the location is the thing being deleted. If a
household has only one location, `move_contents` has no valid target and the client offers
only `delete_contents` — or cancel.

### The Unsorted shelf

A shelf with `is_system = true`, **created lazily** — the first time an `unsort_products`
strategy runs in a location. Rules:

- It is not renameable and not reorderable (it always sorts last).
- It cannot be deleted while it holds products; once empty, it may be deleted like any
  other shelf, and will simply be recreated if needed again.
- Products can be moved *out* of it normally; nothing prevents adding to it directly.

`is_system` is added to `inventory_storage_locations` too, unused for now, purely so a
future household-level holding area does not need a migration.

### Soft delete and undo

Every delete operation stamps a single `deletion_batch_id` (uuid) on **every row it soft-
deletes** — the shelf and the twelve products that went with it share one batch id.

**The client generates the uuid and sends it** as `deletion_batch_id` on each delete request.
This matters: deleting three shelves is three requests, and if the *server* minted the id
they would land in three different batches and Undo would restore only one of them. One
user gesture is one batch, so the id must come from the side that knows where the gesture
starts. The server rejects a batch id that is already present on a *live* (non-deleted) row.

`POST /households/{h}/restore/{batch_id}` clears `deleted_at` for the whole batch, restoring
the operation as a unit. It fails with `409` if a restore would conflict (e.g. the parent
location has since been hard-purged).

The delete response returns the `deletion_batch_id`; the Android snackbar's **Undo** calls
restore with it. There is **no user-facing trash UI** — `deleted_at` exists so a support-
grade restore is always possible, and so that a mis-tap is survivable for the length of a
snackbar.

A scheduled command purges rows soft-deleted more than **30 days** ago (children first).

### Permission seam (D6)

A `HouseholdPolicy@restructure` returning `true` for any member of the household. Every
new mutating endpoint above authorises against it. Spec 2 changes the body of that one
method and nothing else.

---

## Android (`inventory-android`)

### Navigation rework

The Households tab leaves the bottom bar — it is a *management* screen, not a daily
destination, and the Storage tab already lets you act across households.

**Before:** Dashboard · Storage · Households · Missing · Search — with Settings reachable
only via a gear icon in every top bar.

**After:** Dashboard · Storage · **Scan** · Missing · **More**

- **Scan** takes the centre slot. The centre is the primary-*action* slot, and scanning an
  item is a weekly grocery-trip action; searching is an occasional "where did I put it".
  It navigates straight to `ScannerScreen`.
- **Search** loses its tab and keeps the top-bar icon it already has.
- **More** replaces the gear icon and absorbs the old Settings screen **plus Households**:
  language · theme · **My households** · join a household / scan QR · account · version.
  It is called "More", not "Settings", because it is now a management hub.

This contradicts the navigation section of `inventory-android/CLAUDE.md`, which must be
updated as part of this work.

### Edit mode — one pattern, every list

The same interaction on the households list, the locations list, and the shelves list.
A **pencil** in the top bar toggles it. It is shown only when `canRestructure` is true.

**Edit mode off** — rows behave exactly as they do today (tap navigates).

**Edit mode on** — each row grows two things:

- a **checkbox** on the left — multi-select for bulk delete;
- a **drag handle** on the right — reorder.

Tapping the **row body** opens that item's **edit sheet** (rename, and type for locations).
The app bar shows a Delete action, enabled once ≥1 row is checked, plus a Done/✕ to exit.

Tap targets are distinct, so "am I selecting this or opening it?" never arises. This
replaces the current no-confirmation bulk shelf delete outright.

> **Deferred detail:** dragging a shelf *between* locations is not a gesture in this spec.
> The API supports it; the UI does not offer it yet.

### Shelves: tabs ⇄ list

`LocationDetail` shows shelves as a `ScrollableTabRow` + `HorizontalPager`, which falls
apart past ~6 shelves and cannot support drag-to-reorder or inline rename.

A **view toggle** in the app bar switches between:

1. **Tabs + products** (today's view) — good for browsing one shelf's contents.
2. **Shelf list** — shelves as a vertical list, no products.

The choice persists as a global user preference (not per-location).

**Entering edit mode always flips to the list view** and restores the previous view on
exit. This is why the toggle matters beyond convenience: it makes the list a place the user
has already chosen to visit, so edit mode does not feel like a surprise re-layout.

`LocationDetail`'s top bar currently shows a generic `location_shelves_title` string —
it should show the location's actual name, which is now editable.

### Ordering (D3)

Sort key for every list, in order:

1. **manual `position`** (server-side for locations and shelves; device-local for households);
2. **alphabetical**, as the tie-break for items that have never been reordered.

**A star never reorders anything.** It renders as a marker on the row and drives an optional
"starred only" filter. This is a change from today, where favourites float locations to the
top of `AllStoragesScreen`.

Stars today are device-local `SharedPrefs` (`FavoritesStore`) covering **locations and
shelves** only.

- **Products gain a star** (`is_starred`, server-side — "staples" are a household fact, not
  a personal one).
- **Households do not.** With two or three of them, starring one is noise.
- Location/shelf stars stay device-local. Two members of a household legitimately starring
  different shelves is correct; it is also a second reason a star must never be the
  canonical order (D3).

### Collapsible household groups

On `AllStoragesScreen`, each household header collapses. State persists in DataStore
alongside the household display order (D8). It is a view preference, not domain data, so
storing it locally does not violate the server-authoritative rule in `CLAUDE.md`.

### Household edit page

Reached by tapping a household row in edit mode. Contains:

- **name** (new — the DTO already hits an endpoint that accepts it; it just omits the field);
- **colour + icon** — moved here from the palette-icon dialog on the household card, which
  is removed;
- a **danger zone**, visually separated: **Leave household**, moved here from the card.

Leave sits in a danger zone rather than beside the rename field because it is not an edit of
the household — it ends *your membership*, a different blast radius.

**There is still no "delete household."** The backend only offers `leave`, because nobody
*owns* a household. That is a Spec 2 problem, and the danger zone is where it will land.

### Delete flow (UI)

1. User checks ≥1 row in edit mode, taps Delete.
2. Client knows the contents count. If **everything selected is empty**, show a plain
   confirm dialog.
3. If **anything holds contents**, show the strategy dialog. For a **batch**, it summarises
   the whole operation ("3 shelves · 17 products") and applies **one strategy to all** —
   the dialog is not shown once per item.
4. On confirm, the client mints **one** `deletion_batch_id` for the gesture and sends it on
   every request in the batch.
5. Snackbar: *"3 shelves deleted — Undo"*. Undo calls restore with that batch id, which
   brings back all three shelves and their products as a unit.

## Error handling

- **`422` from a delete with no strategy** — a client bug (the client should always know the
  contents count). Surface the generic error and do not mutate local state.
- **Reorder failure** — the list snaps back to server order. Reorder is optimistic; a failed
  write must not leave a half-sorted list, which is why it is one bulk endpoint.
- **Undo after the row is gone** (`409`) — snackbar: "Couldn't undo — this was already
  restored or permanently removed."
- **Delete of the last location while `move_contents` is the only sane strategy** — the
  client disables `move_contents` when no other location exists, leaving delete-or-cancel.
- Live updates over `inventory.household.{id}` already exist; renames, reorders, and deletes
  must broadcast so a second member's list does not go stale mid-edit.

## Testing

**Laravel** — feature tests per delete strategy at both levels (shelf: move / unsort /
delete; location: move / delete), asserting product survival and `deletion_batch_id`
grouping. Restore-by-batch restores parent *and* children. `422` on a strategy-less delete
of a non-empty container. Reorder is transactional. `is_system` shelves reject rename and
reject delete-while-occupied. Policy: `restructure` is denied to a non-member.

**Android** — unit tests for the sort rule (manual order wins; a star does not reorder;
alphabetical only as tie-break). Flow tests for: rename a location; reorder shelves and
confirm the order survives a reload; delete a shelf holding products via each of the three
strategies; undo a delete. Regression test that entering edit mode flips shelves to the list
view and restores the prior view on exit.

## Deferred to Spec 2

**Roles & permissions.** Owner / Admin / Member, per the user's decision on 2026-07-13.
This requires a `role` column on `inventory_household_user` (which today has no such
column), a policy layer across every household-scoped endpoint, role assignment at invite
time, a member-management UI, and a rule for what happens when the last owner leaves. It
also unblocks **delete household**, which cannot exist until someone owns one.

This spec leaves it exactly one function away: `HouseholdPolicy@restructure`.

⚠️ **`inventory-android/CLAUDE.md` currently lists `roles/permissions` under "Scope
guardrails — refuse to add."** That guardrail is overridden by the user's 2026-07-13
decision and must be rewritten as part of this work, along with the navigation section
(which still documents the 5-tab bar and the gear-icon Settings).
