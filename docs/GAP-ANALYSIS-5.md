# Gap Analysis 5 — UI/UX depth pass (2026-07-18)

Second UX audit round: (a) the GAP-4 fixes and household-delete feature
as-built, (b) per-screen depth on auth / scanner / search / product detail /
dashboard, which GAP-4 only checked for cross-app consistency. GAP-4's open
Lows were excluded from re-reporting.

Status legend: 🔴 open · ✅ fixed (commit noted).

## High

- **H1 🔴 Delete-household dialog closes before the server answers** —
  `HouseholdEditScreen.kt`: confirm fires `viewModel.delete(...)` and closes
  the dialog unconditionally; on 422/403 the typed name is lost and the error
  surfaces as an unrelated top-of-screen ErrorRetry whose Retry re-fetches
  the list, not the delete. Keep the dialog open until the result, error
  inline, close on success only.
- **H2 🔴 A second role change silently kills the previous Undo snackbar** —
  `MembersScreen`/`MembersViewModel`: `roleChangeEvent` is a single slot;
  rapid promote/demote sequences drop all but the last Undo with no cue.
- **H3 🔴 Cross-household selection reset is silent** — `DrawerViewModel`
  `toggleSelection`: tapping another household's row replaces the selection
  set; the count just changes. Minimal fix: a "Selection cleared — switched
  to {household}" snackbar.
- **H4 🔴 Password requirements invisible until the server 422s** —
  `AuthScreen`: no supportingText/hint on the password field in register
  mode; the rule is only learnable by failing.
- **H5 🔴 Scanner mode (LOOKUP vs ADD) is invisible** — `ScannerScreen` shows
  the same title in both modes; nothing tells the user whether the scan will
  search globally or add to the shelf they came from.
- **H6 🔴 Scan-with-no-match is a dead end** — a scanned code with zero
  results shows plain "no results"; the app knows the exact code and the
  household — offer "Add a product with this code" pre-filled.
- **H7 🔴 Search state doesn't survive back-nav** — `SearchViewModel` resets
  on household set; returning from a result means retyping the query.
- **H8 🔴 Product detail never shows quantity** — the detail screen has no
  quantity display or stepper; adjusting count requires going back to the
  shelf list. CLAUDE.md describes this screen as "name + quantity."

## Medium

- **M1 🔴** Delete-dialog copy is generic — doesn't state member count or
  scale of data being destroyed.
- **M2 🔴** Disabled-until-match Delete button gives no mismatch feedback
  (strict `==`, no trim; trailing space = mystery-disabled forever).
- **M3 🔴** Danger-zone intro copy still only describes "Leave" though the
  card now holds Leave + Delete + the transfer steer.
- **M4 🔴** AllStorages vs StorageOverview multi-select diverge: Cancel
  changes sides, Delete is an icon on one and a labeled count-button on the
  other. Align to StorageOverview's pattern.
- **M5 🔴** Login/Register are one screen with a low-salience toggle — weak
  first-run framing of which mode you're in.
- **M6 🔴** Forgot-password sent-state has no "wrong email? edit/resend"
  affordance (the no-account-disclosure behavior itself is correct).
- **M7 🔴** No torch/flashlight in the scanner — dark fridges/cupboards are
  this app's home turf; CameraX's `enableTorch` is one line from the
  already-bound Camera.
- **M8 🔴** Camera-permission denial has no "Open settings" path — once
  denied, un-denying requires leaving the app with no guidance.
- **M9 🔴** Non-deep-linkable search results are visually identical to
  tappable ones (missing onClick, no muted state).
- **M10 🔴** Quantity stepper is tap-only ±1 — no long-press repeat, no
  direct numeric entry; 0→24 is 24 taps.
- **M11 🔴** Dashboard zero-state for an empty-but-real household is three
  bare zeros with no "add your first location" pointer (the no-household
  dialog exists; the empty-household case doesn't).
- **M12 🔴** Verify `product_detail_mandatory_hint` explains the downstream
  effect (drives missing/running-low), not just the flag's existence.

## Low

- **L1 🔴** Undo snackbar is obscured while any dialog is open (Compose
  dialog window layering) — edge timing case.
- **L2 🔴** Search result path fallback can degrade to `" › "` when fields
  are blank.
- **L3 🔴** No spinner/shimmer between photo pick and upload finish; no
  styled error/retry for a failed remote image load.
- **L4 🔴** Scan-originated no-results shares Search's plain empty state
  (superseded if H6 lands).

## Verified healthy (no action)

Role chips fully theme-derived (light/dark parity automatic); FrostCard swaps
match sibling padding/shape; the sole-owner leave→transfer steer copy is
clear; auth keyboard wiring (Next/Done/IME) is solid; forgot-password
correctly avoids account disclosure; scanner overlay polish; dashboard
"running low"/missing cards fully tap-through actionable; multi-household
attribution appears only when meaningful; low-stock threshold field well
placed with inline hint.
