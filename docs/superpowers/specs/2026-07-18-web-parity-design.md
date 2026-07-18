# Web parity program — Design

**Status:** approved direction (user decisions 2026-07-18), ready for planning
**Repo:** `inventory-laravel` only (the Android app is the reference implementation, not a work item here)

## The decision this encodes

The web UI drifted from "thin Blade convenience surface" to near-parity by
accretion. On 2026-07-18 the user settled its long-term role: **the web is a
full-parity product**, equal to the Android app, with exactly one deliberate
exception — **barcode scanning stays app-only** (device-affine; the existing
cross-hint remains the handoff). Tech decision, made against a working
side-by-side demo: **adopt Alpine.js** for the interactive layer (instant
reorder, styled dialogs, snackbar undo) rather than staying form-post-only.

This supersedes the "thin Blade" scoping decision of 2026-07-10 and both
CLAUDE.md files' "Android is the only API client / web is a thin surface"
framing — updating that framing is part of this program.

## Parity gaps to close (from GAP-ANALYSIS-6's cross-surface matrix)

1. **Reorder** — locations and shelves get instant up/down reordering
   (Alpine: optimistic swap + background PATCH to the existing `/reorder`
   web-mapped endpoints, which must be added to `routes/web.php` — the API
   ones exist; mirror them). On fetch failure: snap back + inline error.
2. **Delete strategies, complete** — the shelf picker (shipped, GAP-6 H5)
   gains `move_products` with a target-shelf select; the location delete
   gains `move_contents` with a target-location select. Alpine dialog
   replaces the native `confirm()` mocks, matching the Android
   `DeleteStrategyDialog` semantics exactly (never default to destruction;
   no move option when no target exists; system shelf excluded as target
   per the LOCKED rules).
3. **Undo / restore** — deletes surface a snackbar-style toast with Undo
   wired to the existing restore-by-batch endpoint (web route to add,
   mirroring `POST households/{household}/restore/{batch}`). Additionally a
   "Recently deleted" view (per household) listing restorable batches within
   the retention window — this also closes GAP-6 H5's cross-surface-recovery
   remainder, since it works regardless of which surface minted the batch.
4. **Product photo upload** — the web product edit page gains an image
   upload field posting to a web-mapped twin of the existing API image
   endpoint (same validation); it already displays `image_url`.
5. **Light mode** — a light theme matching the Android app's light palette
   (steel-blue ground, teal-blue primary — see `ui/theme/` in the app repo),
   via `prefers-color-scheme` with a manual toggle persisted in a cookie.
   This settles GAP-6 L2.
6. **Language toggle** — a visible EN/NL switch (session/cookie-persisted)
   layered over the existing Accept-Language negotiation; also translate
   Laravel's default validation messages (the known M4 leftover) by
   publishing/adding `lang/nl` validation lines for the rules actually used.
7. **Doctrine/docs** — CLAUDE.md (both repos) rewritten: web is a
   first-class equal surface, Alpine is sanctioned, scanning is the named
   app-only exception. The API-contract doc notes which web routes mirror
   which API routes.

## Tech constraints

- **Alpine.js self-hosted** (vendored into `public/`, published like other
  package assets) — no CDN (ad-blockers, offline dev, and the repo's
  no-external-dependency posture for serving).
- Alpine is an enhancement layer: every mutating interaction keeps a
  working non-JS form fallback where practical (progressive enhancement),
  so the PHPUnit feature tests keep testing real routes. Client-only
  behavior (optimistic swap, toast timing) is accepted as untested by the
  suite — noted per component with a comment.
- New web endpoints are thin wrappers over the same services/policies the
  API uses (`HierarchyDeleter`, `HouseholdPolicy`, restore controller
  logic) — no duplicated authorization or strategy semantics, matching how
  member management was built.
- `/api/v1` remains untouched and backward compatible throughout.

## Out of scope

- Barcode scanning on web (the one permanent exception — user decision).
- SPA rewrite, build tooling, npm pipeline — Alpine is used as a single
  vendored script, no bundler.
- Realtime granular DOM patching — the dirty-form-aware reload hint
  (GAP-6 M5 fix) stays; Alpine pages may upgrade it opportunistically but
  a full live-sync layer is not part of this program.

## Testing

Feature tests for every new web route (reorder, restore, image upload,
recently-deleted view) covering the same invariant matrix as their API
twins (tenancy, roles, strategy validation, system-shelf exclusion); a
locale test for the toggle; a smoke assertion that vendored Alpine is
served. Client-side behavior is exercised manually against the demo
checklist (reorder feel, dialog flows, undo countdown) before each release.
