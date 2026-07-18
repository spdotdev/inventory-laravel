# Gap Analysis 6 — journeys, as-built fixes, cross-surface (2026-07-18)

Third UX audit round, with lenses the first two structurally couldn't cover:
(a) the GAP-5 Medium/Low fix waves as-built, (b) five end-to-end journeys
traced through the nav code, (c) cross-surface web↔Android coherence.
Everything already tracked in GAP-4/5 (including their ✅-annotated leftovers)
was excluded from re-reporting.

Status legend: 🔴 open · ✅ fixed (commit noted).

## High

- **H1 ✅ (android f85cb45) Stepper hold-delta silently lost on gesture cancel** — Android
  `ui/common/RepeatingIconButton.kt`: the accumulated ticks only flush on a
  clean pointer-up; navigate-away/backgrounding/config-change cancels the
  gesture coroutine and the count the user watched go up is silently never
  sent. Flush in a try/finally or NonCancellable block.
- **H2 ✅ (android e830cc5) Failed stepper release-send leaves the wrong count on screen** —
  `ProductsPane`/detail: `pendingDelta` only resets when the server quantity
  changes; on send failure the optimistic number persists indefinitely with
  only a generic snackbar. Reset the delta on failure + a specific
  "quantity didn't update" message.
- **H3 ✅ (android 8701808) Every error message is hardcoded English, app-wide** —
  `data/error/ErrorMapping.kt` returns EN literals for all failure paths
  while the rest of the UI is EN+NL: a Dutch UI reverts to English the
  moment anything fails, on every screen. Route the mapper through string
  resources.
- **H4 ✅ (backend 8c91177 + android b005e08) Unsorted shelf unlocalized in search results** — backend
  `SearchResultResource` returns the raw DB name; the client renders it
  verbatim instead of gating on `is_system` like every other screen
  (`shelfDisplayName()`). Return `is_system` in the resource + localize
  client-side.
- **H5 ✅ (web 8c7a984, move strategies omitted by scope) Web location/shelf deletes have no strategy and no recovery
  affordance** — `WebLocationController`/`WebShelfController` hardcode
  delete-contents; the identical action Android treats as its LOCKED
  delete-doctrine (mandatory strategy dialog) is one `confirm()` on the web,
  and although the delete is soft, no surface offers a restore UI for a
  batch minted by the other surface — in practice unrecoverable. Add a
  lightweight strategy picker or a "recently deleted" recovery view.

## Medium

- **M1 ✅ (android 6d814a3)** Search and ProductDetail are the only screens that don't react to
  `household.changed` pings — exactly where a user "watching" a change sits.
- **M2 ✅ (android 902c4c4)** The first-run pencil hint stays visible inside edit mode,
  explaining an icon that's no longer on screen while competing with the
  selection UI. Hide when `editMode`.
- **M3 ✅ (android 84d1349)** Dashboard covers zero-locations but not locations-with-zero-
  products: an all-zero bar chart with no add-a-product nudge.
- **M4 ✅ (web dcaf230; Laravel default validation strings still EN)** Web `/app` pages are English-only (`lang="en"` hardcoded, zero
  `__()` calls) despite `lang/nl` existing and the landing page proving the
  pipeline — a real seam for Dutch households mixing surfaces.
- **M5 ✅ (web 34942f2)** Web live-update is `location.reload()` — a ping mid-form destroys
  unsaved input and scroll; Android's equivalent is a silent background
  refresh. Skip/delay reload while a form is dirty, or fragment-reload.
- **M6 ✅ (web 81fd7e9 + android fba19c6)** Capability asymmetries with no cross-references: export is
  web-only (invisible from the app), product photos are app-only (not even
  rendered on web), reorder is app-only. Each surface should hint at the
  other where relevant.

## Low

- **L1 ✅ (android, primary tint)** AuthScreen mode-switch (GAP-5 M5 as-built) styled with
  `onSurface` strips the tappable-link affordance — reads as a caption.
- **L2 ✅ (web-parity T6, 1189f6d)** Web has no light mode; Android's light theme is a different
  palette entirely — brand recognition across surfaces only holds in dark
  mode. Document dark-only as a decision or add a light variant.

## Verified healthy (no action)

Cold-start-to-first-product journey has an explicit CTA at every empty state
(no dead ends); partner-join refreshes the hierarchy so the new household
appears without a manual pull; the H7 search-persistence fix genuinely holds;
Missing updates live as items are restocked; the delete-dialog error idioms
are mutually exclusive by construction; NL strings from both fix waves are
natural, consistent-register Dutch; terminology (nouns, roles, Unsorted,
typed-name delete confirm) matches across surfaces; `/join/{code}` on web is
a sensible get-the-app handoff; role management gating matches on both
surfaces.
