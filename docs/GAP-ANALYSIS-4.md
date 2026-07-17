# Gap Analysis 4 — UI/UX (2026-07-17)

Third-party-eyes UX audit run the day after the household-roles release, three
lenses: the new roles/members surfaces (Android), cross-app consistency of the
core flows (Android), and the web account UI. Correctness was covered by the
same-day stability audit; this list is purely user-experience.

Status legend: 🔴 open · ✅ fixed (commit noted).

## High

- **H1 🔴 Sole-owner "Leave" dead-ends** — Android `HouseholdEditScreen`: the
  leave-confirm dialog never warns an Owner the action will 409, and the error
  then renders as static text with no path to the fix (Transfer ownership, on
  the Members screen). Fix: warn/disable up front for Owners + make the error
  actionable.
- **H2 🔴 Role gating is invisible** — Android, all `can_restructure`-gated
  screens: controls simply don't exist for a Member; no caption anywhere
  explains that a role system exists, what the viewer's role is, or why
  editing is unavailable. Demotions mid-session are silent.
- **H3 🔴 Web transfer-ownership has no confirmation** —
  `resources/views/web/household.blade.php`: remove-member and leave both
  `confirm()`; the most consequential click on the page submits instantly.
- **H4 🔴 Web member table doesn't mark "(you)"** — all rows render
  identically; combined with H3, transferring to the wrong person is one
  misread away.
- **H5 🔴 `EditableRow` name text can overflow** — no `maxLines`/`overflow` on
  a row that gains up to three 48dp buttons in edit mode; the exact
  font-scale-1.6 ellipsize bug class from issue #31 (locations + shelves
  lists both affected).
- **H6 🔴 Dashboard & Missing have no first-load spinner** — blank screens
  during the initial fetch while five sibling screens show a
  `LinearProgressIndicator` for the same condition.

## Medium

- **M1 🔴** Promote/demote are silent one-tap actions (Remove confirms; a
  permission grant doesn't) and have no Undo/snackbar.
- **M2 🔴** No new-joiner onboarding: joining via code lands you as Member
  with zero explanation of the role model.
- **M3 🔴** Role badge is plain `Text` — no chip/color; Owner/Admin/Member
  scan identically in the roster.
- **M4 🔴** Three parallel error idioms (`ErrorRetry` with retry /
  `LiveStatusText` without / snackbar-only). Products screens have no
  persistent error + retry at all — a missed snackbar leaves a blank screen.
- **M5 🔴** The pencil means two interaction grammars: multi-select +
  strategy dialog on StorageOverview/LocationDetail, single per-row delete on
  AllStorages.
- **M6 🔴** `Card` vs `FrostCard` drift on the two newest screens
  (HouseholdEdit's members entry, MembersScreen rows).
- **M7 🔴** Web household page is seven flat equal-weight cards — invite
  sits at the same visual priority as transfer-ownership; no
  sectioning/danger-zone layout.
- **M8 🔴** Web actions `back()` to the top of a long page with a generic
  flash — scroll position and context lost after every member action.

## Low

- **L1 🔴** Members screen loading is an overlaid spinner, no empty-state
  polish (unreachable in practice; consistency nit).
- **L2 🔴** Members roster rows lack TalkBack semantic grouping.
- **L3 🔴** No shared spacing tokens — ~207 hand-typed `.dp` literals;
  measurable padding drift between Auth/Dashboard/Scanner.
- **L4 🔴** Web validation errors are one generic top-of-page flash, not
  field-level.
- **L5 🔴** Web members table lacks an `overflow-x` wrapper — awkward on
  narrow phones from invite links.
- **L6 🔴** "No locations yet." empty state doesn't frame the add form below
  it as the first action.
- **L7 🔴** No "get the app" cross-promotion anywhere on the web surface —
  needs a product decision (or explicit acceptance of web parity).
- **L8 🔴** Missing tab root shows a back arrow other tab roots omit.
- **L9 🔴** No first-run hint for the edit-mode pencil.
- **L10 🔴** Auth/ForgotPassword and Settings join-household use bespoke
  inline feedback instead of the app's shared idioms (overlaps M4).

## Verified healthy (no action)

Delete→Undo architecture (batch ids, sequencing, no double feedback);
navigation branching including the four household-picker search entry points;
top-bar icon placement matches CLAUDE.md exactly; no dead-end screens; edit
mode shows selection count and a labelled exit; decorative-only
`contentDescription = null` usage; touch targets ≥48dp; NL translations of the
new roles strings are idiomatic with no length issues.
