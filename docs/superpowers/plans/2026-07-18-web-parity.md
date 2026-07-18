# Web Parity Program — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the web surface to full parity with the Android app (spec: `docs/superpowers/specs/2026-07-18-web-parity-design.md`) — Alpine.js interactions, complete delete strategies, undo/restore, photo upload, light mode, language toggle — with barcode scanning as the one permanent app-only exception.

**Architecture:** Alpine.js vendored as a single script, used as a progressive-enhancement layer over the existing thin-Blade forms; every new web endpoint is a thin wrapper over the services/policies the API already uses (`HierarchyDeleter`, `HouseholdPolicy`, restore logic). `/api/v1` untouched throughout.

**Tech Stack:** Laravel 13 Blade + vanilla forms (fallback) + Alpine.js (vendored, no bundler). Gates: PHPUnit, Pint, `phpstan analyse --memory-limit=1G`.

## Global Constraints

- **Feedback & error visibility rules from the spec are binding on every task** with a background save: visible saving indicator, visible success, loud revert + plain-words error + Retry on failure, beforeunload guard while unsaved, dirty-aware live-update hint preserved. Never silent success, never silent failure, never client state the server doesn't have beyond the in-flight window.
- Progressive enhancement: the non-JS form fallback keeps working for every mutation where practical; PHPUnit tests the real routes.
- New web endpoints mirror API twins' full invariant matrix (tenancy 404-not-403, role gates via the same `HouseholdPolicy` methods, strategy validation, system-shelf exclusion). No duplicated authorization logic.
- All new strings `__()`-wrapped with NL entries in `lang/nl.json` (je-register, Android app's terminology).
- One commit per task; full gate green before each commit.

---

### Task 1: Alpine foundation + shared feedback components
**Files:** vendor Alpine into `public/vendor/alpine.min.js` (download pinned version, note version + SHA in a comment/README line; wire into `InventoryServiceProvider` asset publishing like existing public assets); `layout.blade.php` loads it + gains shared partials: `partials/savebar.blade.php`, `partials/toast.blade.php`, and a small `web-feedback.js` (also vendored/inline) implementing: fetch wrapper with saving-bar + success/failure toasts + retry callback + beforeunload guard while in-flight/failed, per the spec's feedback rules. Extend the live-updates dirty check to also count Alpine in-flight state as dirty.
**Test:** feature test asserting the Alpine asset route/file is served; a JS-free page still renders (no hard dependency).

### Task 2: Reorder on web (locations + shelves)
**Files:** `routes/web.php` + `WebLocationController::reorder`/`WebShelfController::reorder` (thin wrappers mirroring the API reorder endpoints incl. complete-list validation + `restructure` gate + system-shelf exclusion); `household.blade.php` (locations list) and `location.blade.php` (shelves list) get ↑/↓ controls: Alpine optimistic swap + background PATCH via the Task-1 fetch wrapper; non-JS fallback = plain per-row move-up/move-down form posts hitting the same endpoints with a `direction` param variant (or full-list POST — pick the cleaner; document).
**Test:** feature tests mirroring the API reorder matrix (partial list 422, non-member 404, member 403, system shelf excluded, order persisted).

### Task 3: Complete delete strategies with move targets
**Files:** `WebShelfController::destroy` + `WebLocationController::destroy` accept `move_products`/`move_contents` + `target_shelf_id`/`target_location_id` (validated: exists, same parent scope, not the deleted one, not a system shelf — mirror the API's rules by reading `ShelfController`/`LocationController` destroy validation); `location.blade.php`/`household.blade.php` delete flows become an Alpine dialog matching Android's `DeleteStrategyDialog` semantics (safest available default, move option only when a target exists, target select populated; native-confirm fallback for no-JS keeps the current unsort/delete-only subset).
**Test:** move_products relocates products to the chosen shelf; move_contents relocates shelves; invalid/foreign/system target 422; no-target households don't offer move (view test on rendered options).

### Task 4: Undo + Recently deleted
**Files:** web route `POST /households/{household}/restore/{batch}` → thin wrapper over the API `RestoreController` logic (`restructure` gate, 409 semantics); delete responses carry the batch id (session flash or JSON for Alpine callers); Alpine toast with countdown Undo after any hierarchy delete (Task-1 toast component); new "Recently deleted" section/page per household listing restorable batches within retention (query soft-deleted rows grouped by `deletion_batch_id` — check what exists server-side to support listing; add a small query/service if needed, read-only) with a Restore button each; linked from the household page.
**Test:** undo restores the batch (products/shelves back); 409 parent-dead case surfaces the API's message; recently-deleted view lists a batch and restores it; batches older than retention absent.

### Task 5: Product photo upload on web
**Files:** web route + `WebProductController::image` mirroring the API image endpoint's validation (mimetypes, max size) and storage; `product-edit.blade.php` gains the upload field (plain form post — no Alpine needed; the existing image display stays).
**Test:** upload stores + sets `image_url`; invalid type/size 422; tenancy/role matrix.

### Task 6: Light mode + theme toggle
**Files:** `layout.blade.php` CSS re-tokenized to custom properties with a light palette matching the Android light theme (reference `ui/theme/` in the app repo: steel-blue ground, teal-blue primary, near-white cards); `prefers-color-scheme` default + manual toggle (Alpine, cookie-persisted, `data-theme` attr). Both themes checked for contrast on all existing components (badges, danger zone, flashes, dialogs, toasts).
**Test:** toggle cookie changes the rendered `data-theme`; smoke assertion both palettes define all tokens.

### Task 7: Language toggle + validation translations
**Files:** visible EN/NL switch in the layout (cookie/session-persisted, overriding Accept-Language; extend `NegotiateLocale` to read it); `lang/nl/validation.php` covering the rules the web forms actually use (required, string, max, integer, uuid, in, exists, image/mimetypes/max for uploads — grep the form requests/validate calls); field names localized via `validation.attributes`.
**Test:** toggle switches locale and persists; a failed NL-locale validation renders the Dutch message.

### Task 8: Doctrine + docs reconciliation
**Files:** both repos' CLAUDE.md: replace the "Android is the only API client / thin web" framing with the parity doctrine (equal surfaces, Alpine sanctioned with the feedback rules, scanning the named app-only exception); `docs/specs/api-contract.md` (or sibling) notes web↔API route mirroring; GAP-ANALYSIS-6 L2 marked settled by Task 6. Android repo: update the cross-hint strings if any say "only in the app" for things now on web (grep).
**Test:** none (docs); gate still green.
