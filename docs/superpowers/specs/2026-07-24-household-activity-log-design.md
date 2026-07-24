# Household activity/audit log (MCP-only) — design

**Date:** 2026-07-24
**Status:** approved, pending implementation plan
**Scope carve-out:** this is a narrow, explicit exception to the locked "no
activity/audit log" guardrail in `CLAUDE.md` — see that file for the exact wording.
This feature is **MCP-only**: no Android or web UI will ever surface it. It exists so
an operator (or the user, via MCP/Claude) can inspect what happened in a household,
not as a member-facing product feature.

## Problem

There's currently no way to answer "who did what, when" for a household. The existing
`HouseholdChanged` broadcast (via `BroadcastHouseholdChange` observer) is a
no-payload "something changed, go refetch" ping for live-update clients — it records
no actor, no diff, and isn't persisted. Cascading deletes are covered by
`deletion_batch_id` + `RecentlyDeleted`, but that's scoped to deletions only, has no
actor/diff detail, and is subject to `deleted_retention_days` pruning.

## Scope

**In scope:** an immutable log of household/location/shelf/product
create/update/delete, member add/remove/role-change/ownership-transfer, and
household create/delete — the same breadth of events `BroadcastHouseholdChange`
already observes, plus the manual-dispatch call sites (pivot writes, stock actions,
delete/restore). Two read surfaces, both MCP-only: an admin cross-household view and
a per-household member view.

**Out of scope:** any Android/web UI, any prune/retention job (kept forever), any
new permission model beyond "any member can view their own household's log."

## Data model

New table `inventory_activity_log`, immutable (append-only, no `updated_at`):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `household_id` | bigint, indexed | |
| `actor_id` | bigint, nullable | nullable for any system-triggered action (none currently exist, but don't force a fake actor later) |
| `action` | string | dotted verb, e.g. `product.created`, `product.updated`, `product.deleted`, `location.created`, `shelf.deleted_batch`, `member.added`, `member.removed`, `member.role_changed`, `household.ownership_transferred`, `household.created`, `household.deleted` |
| `subject_type` | string | polymorphic target class, e.g. `Product`, `HouseholdUserPivot` |
| `subject_id` | bigint, nullable | nullable for batch-summarized entries covering multiple rows (see Cascading deletes) |
| `subject_label` | string | denormalized name/identifier snapshot, so the entry still reads sensibly after the target is later renamed/deleted |
| `changes` | json, nullable | before/after diff for updates (`{"field": {"from": ..., "to": ...}}`); null for create/delete where the row snapshot itself is the record |
| `created_at` | timestamp | the only timestamp column; entries are never modified |

Model: `src/Models/ActivityLogEntry.php` (no `updated_at`, `$timestamps` limited to
`created_at` via `const UPDATED_AT = null`).

No retention/pruning — kept forever, per explicit decision (this is a low-volume
audit trail relative to product data, and permanence is the point).

## Capture mechanism

A new observer, `src/Observers/RecordActivityLog.php`, registered on the same models
as `BroadcastHouseholdChange` (`Household`, `StorageLocation`, `Shelf`, `Product`),
hooking `created`/`updated`/`deleted`:

- **created/deleted:** snapshot the row's key attributes as `subject_label`; no
  `changes` diff (the create/delete itself is the record).
- **updated:** build `changes` from `$model->getChanges()` / `$model->getOriginal()`,
  excluding volatile/uninteresting columns (`updated_at`).
- **actor resolution:** `auth()->user()?->id` from the current request context. This
  observer fires within the same request as the mutating controller action, so the
  authenticated user is always available when one exists.

Pivot writes and self-dispatching stock methods fire no Eloquent events (same reason
`BroadcastHouseholdChange` needs manual dispatch calls at those sites) — a small
`ActivityLog::record(...)` helper (`src/Support/ActivityLog.php`) is called
explicitly from:
- `MemberController` (`update` for role changes, `destroy` for removal, plus the
  add-member path and `OwnershipTransfer`)
- `Product::addStock()`/`removeStock()`
- `HierarchyDeleter` (one summarized entry per delete batch — see below)
- `Restorer` (one entry per restore batch, action `*.restored`)
- Household creation/deletion controller actions

### Cascading deletes

A single delete gesture (e.g. deleting a location that cascades to its shelves and
products) writes **one summarized entry per `deletion_batch_id`**, not one row per
cascaded record — mirroring how `deletion_batch_id` already groups these rows for
`RecentlyDeleted`. The entry's `subject_type`/`subject_id` point at the top-level
deleted row (the location); `subject_label` and a `changes`-shaped summary record
counts, e.g. `{"cascaded": {"shelves": 2, "products": 7}}`.

## API surface

Two new endpoints, no new Android/web client code ever calls either:

- **`GET /api/v1/admin/activity`** — admin-token-gated (same static
  `INVENTORY_ADMIN_TOKEN` pattern as `AdminController`'s existing listings).
  Cross-household. Filters: `household_id`, `actor_id`, `action`, `subject_type`,
  `from`/`to` (date range). Paginated with the same `page`/`per_page` convention as
  the 2026-07-24 admin-pagination feature (default 50, max 100), trimmed custom
  `meta` block.
- **`GET /api/v1/households/{household}/activity`** — Sanctum + `household.member`
  middleware (any member, consistent with other read endpoints like
  `RecentlyDeleted` — this is read access to household data, not a management
  action). Same filters minus `household_id` (implicit from the route), same
  pagination convention.

Both return entries newest-first. Response shape: an `ActivityLogEntryResource`
exposing `id`, `action`, `actor` (id + name, or null), `subject_type`, `subject_id`,
`subject_label`, `changes`, `created_at`.

## MCP tools

Added to `docs/specs/mcp-tools.json` (the cross-repo manifest) and mirrored in both
the standalone Node server (`inventory-mcp`) and the embedded PHP tools
(`inventory-laravel/src/Mcp/Tools/`):

- **`list_activity_log`** — admin-token surface, wraps the cross-household endpoint;
  same filter/pagination params as the endpoint.
- **`get_household_activity`** — `userToken`-gated surface (alongside the existing
  `list_deleted`/`export_household` pattern), wraps the per-household endpoint.

Neither tool is referenced by Android or web — this satisfies "MCP-only."

## Testing

Feature tests per capture site (create/update/delete on each of the four observed
models, member add/remove/role-change/ownership-transfer, cascade-delete batch
summarization, stock add/remove) verifying the correct `action`/`subject_label`/
`changes` shape lands in `inventory_activity_log`. Endpoint tests for both surfaces
(admin cross-household filtering + pagination; Sanctum per-household scoping —
member of household A cannot see household B's log; a non-member gets 404, not 403,
consistent with existing tenancy conventions). MCP conformance test coverage
(`scripts/conformance.mjs`) for both new tools, and standalone-server unit tests
mirroring the existing admin-pagination test patterns.
