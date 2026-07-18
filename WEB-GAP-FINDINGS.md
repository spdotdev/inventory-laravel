# Web-side gap/bug audit findings

Audit-only log (ralph loop, 2026-07-18). No fixes applied. Ordered by area; severity tags: [H]/[M]/[L].

**Status: all 29 findings fixed in the GAP-7 wave (same day). This file is the audit record.**

## Iteration 1 — auth, household controller, household page, layout

1. **[M] Self-transfer of ownership renders a raw 422 error page, not a redirect-back.**
   `src/Http/Controllers/Web/WebHouseholdController.php:249` — `abort_if(..., 422, __("You're already the owner."))` in a web (session/Blade) flow produces Symfony's bare error page instead of `back()->withErrors()`. Only reachable via a crafted POST (the select excludes the owner), but every other validation failure in this controller redirects back with a styled error. Inconsistent failure surface.

2. **[M] Web join is not idempotent-safe under race and diverges from the API.**
   `src/Http/Controllers/Web/WebHouseholdController.php:70-72` uses `exists()` check then `attach()` (TOCTOU: double-submit can insert a duplicate pivot row), while the API (`Api/HouseholdController.php:63`) uses `syncWithoutDetaching()`. Web should use the same call — spec says web wrappers reuse API semantics.

3. **[L] Joining a household you're already in reports "Joined :name."**
   `WebHouseholdController.php:80-82` — the already-a-member path falls through to the success flash, misleading the user into thinking a new join happened. API distinguishes nothing either, but on web the flash message is user-facing copy.

4. **[L] Household create + owner attach is not transactional.**
   `WebHouseholdController.php:46-52` (and the API twin) — if `attach()` fails after `create()`, an owner-less orphan household with a minted join code persists. The join-heal path masks it only if someone later joins by that code.

5. **[L] Transfer-ownership form renders with an empty required `<select>` when the owner is the only member.**
   `resources/views/web/household.blade.php:245-256` — the `@can('transferOwnership')` block doesn't check that a non-owner member exists; a sole owner sees a Transfer form whose select has zero options (submit silently blocked by `required`, confirm string reads "…to undefined?" territory). Should be hidden or replaced with a hint.

6. **[L] Join code input is not normalized (trim/case) on either surface.**
   `WebHouseholdController.php:61,66` and `JoinHouseholdRequest` — a code pasted with a trailing space or lowercase fails on a case-sensitive collation. At minimum `trim()`; parity with whatever `generateUniqueJoinCode()` emits.

7. **[L] Google Fonts CDN dependency in the app layout.**
   `resources/views/web/layout.blade.php:8-9` — Alpine was deliberately vendored ("self-hosted, no CDN") but the fonts still load from fonts.googleapis.com: third-party request on every authed page (privacy/EU-transfer posture + broken typography offline/blocked). Landing page may share this.

## Iteration 2 — location/shelf/product/restore/search controllers + location, product-edit, search views, partials

8. **[H] A plain Member gets an Undo button that lands on a raw 403 page.**
   `src/Http/Controllers/Web/WebProductController.php:96-113` — product delete is ungated (any member, matching the API), and it flashes `undo`, so `partials/undo-toast.blade.php` renders the Undo form for everyone. But `WebRestoreController` (`:31`) requires `restructure` — a Member who deletes a product and clicks Undo gets a bare 403. Same story with the "Recently deleted" card: it is `@can('restructure')`-wrapped (`household.blade.php:212`), so a Member has NO working recovery path for their own deletions on any surface.

9. **[H] Restructure-gated controls are rendered for Members and fail with a bare 403 page.**
   The household page wraps reorder/delete/appearance behind `@can('restructure', ...)`, but:
   - `resources/views/web/location.blade.php:61-108` (Delete shelf, incl. strategy dialog), `:162-169` (Add shelf), `:181-209` (Delete location) have **no `@can` wrapper** — every Member sees them; the controllers `Gate::authorize('restructure')` → Symfony 403 page, no styled error, no way back.
   - `resources/views/web/household.blade.php:123-136` (Add location form) — same: visible to Members, `WebLocationController::store:34` 403s.
   Either gate the markup or convert authorize failures to `back()->withErrors()` on this surface.

10. **[H] `<noscript>` delete forms destroy a whole subtree with zero confirmation.**
    `resources/views/web/location.blade.php:195-202` and `resources/views/web/household.blade.php:101-108` — the no-JS fallback for deleting a non-empty location hard-codes `strategy=delete_contents` and relies on `onsubmit="return confirm(...)"` for confirmation. Inside `<noscript>`, JS is by definition disabled, so the confirm never runs: one accidental click deletes every shelf and product in the location with no dialog. (Restorable via Recently deleted, but the spec's "never default to destruction" is violated exactly in the path that was built for safety.) Same latent issue on every `onsubmit=confirm` form when JS fails to load while noscript stays hidden — then *neither* the Alpine dialog *nor* the fallback confirm exists.

11. **[M] Web product edit has no `is_starred` control — parity gap.**
    `resources/views/web/product-edit.blade.php` — the API/Android support `is_starred` (`ProductRequest.php:34`), the web edit form only exposes mandatory/threshold. A star set in the app is invisible and untogglable on the web ("full feature parity" spec; barcode is supposed to be the only exception). Note `WebProductController::update` normalizes only `is_mandatory`, so if a starred checkbox is ever added, unchecking it will silently not persist (same checkbox-absent trap the comment at `:45` describes).

12. **[L] Product edit page shows neither quantity nor stock controls.**
    `product-edit.blade.php` — no current quantity anywhere on the page (the Android equivalent had the same gap flagged in GAP-5 and got it fixed). Stock is only adjustable from the location page.

13. **[L] Search results don't link to the product.**
    `resources/views/web/search.blade.php:20-30` — rows link to the location page only; no Edit/product link, so "find → fix" needs a second visual search on a possibly long page.

## Iteration 3 — web-feedback.js, live-updates partial, NegotiateLocale, login/register views, password reset

14. **[M] No way to request a password reset from the web.**
    `resources/views/web/login.blade.php` has no "Forgot password?" link, and no web route/form exists to trigger the reset email (`routes/web.php` only has the token-consuming GET/POST `/reset-password`). The email can only be requested via the API (Android app) — a web-only user who forgets their password is stuck. Parity spec says web is a first-class surface.

15. **[M] Password reset does not invalidate live web sessions.**
    `src/Http/Controllers/ResetPasswordController.php:61-68` revokes all Sanctum tokens but never touches `inventory`-guard sessions — a hijacked/old browser session survives a password reset indefinitely (no `Auth::logoutOtherDevices`, no session-guard invalidation). The comment's stated goal ("any stolen bearer token stops working") now has a web-shaped hole.

16. **[L] Password reset error strings are hard-coded English.**
    `ResetPasswordController.php:52,59` — 'This password reset link is invalid or has expired.' / 'No account found for this email address.' are not wrapped in `__()`, on a page that IS locale-negotiated (`inventory.locale` middleware). GAP-6 localized errors everywhere else.

17. **[M] Error toast auto-dismisses after 8s taking the only Retry affordance with it, while the "failed" state persists.**
    `public/js/web-feedback.js:80-81,157-172` — `hasFailedSave` stays true after the toast (and its Retry button) is removed, so `beforeunload` keeps prompting and live-updates treats the page as dirty forever, but the user no longer has any retry UI. Either persist error toasts until dismissed/retried, or clear the failed flag when the toast expires (state was already reverted).

18. **[L] Error toasts use `role="status"` (polite) instead of `role="alert"`.**
    `web-feedback.js:60` — failure toasts are announced at the same priority as "Saved.", so screen-reader users may miss a revert+error entirely, especially with the 8s TTL of finding 17.

19. **[M] `NegotiateLocale` overwrites the `Vary` header and omits `Cookie`.**
    `src/Http/Middleware/NegotiateLocale.php:41` — `headers->set('Vary', ...)` clobbers any Vary set earlier in the stack, and since the effective locale (plus display mode) is cookie-driven, `Vary: Accept-Language` alone lets a shared cache serve a cookie-picked NL body to an EN visitor with the same Accept-Language. Should append, and include `Cookie` (or mark these pages no-store).

20. **[L] Register page shows no password rules until rejection.**
    `resources/views/web/register.blade.php` — no min-8 hint next to the password field; the same gap GAP-5 flagged (and fixed) on Android. Also no `autocomplete="new-password"` / `autocomplete="current-password"` hints on register/login fields, and `login.blade.php`'s password input lacks `autocomplete` too.

21. **[L] Successful password reset renders a view from the POST response.**
    `ResetPasswordController.php:72-73` — returning the success view directly (no redirect) means browser refresh re-submits the form (now failing on the consumed token, showing an error after a successful reset). Post/Redirect/Get is used everywhere else on the surface.

## Iteration 4 — join flow, landing/join views, rate limiters, config, i18n sweep

22. **[H] Web household-join is not rate limited — invite codes brute-forceable via the browser.**
    `routes/web.php:53` — `POST /app/households/join` carries no throttle, while the API twin has `throttle:inventory-join` (`routes/api.php:64`) precisely because join codes are guessable. Any authenticated web user can hammer codes at full speed.

23. **[M] `POST /reset-password` is not rate limited.**
    `routes/web.php:24` — outside the `throttle:inventory-auth` group; unlimited token-guessing attempts per email (token is 60-char hashed random, so practical risk is low, but it's also an unauthenticated DB-hitting endpoint with zero abuse bound, unlike every other auth surface here).

24. **[M] `/join/{code}` page ignores the web app entirely.**
    `src/Http/Controllers/JoinController.php` + `resources/views/join.blade.php` — the invite landing only says "open the Android app", predating the web-parity decision. The web UI has a working join form (`inventory.web.households.join`); the page should offer "sign in on the web and join" (ideally pre-filling/auto-joining with the code after login). Today a browser recipient must manually retype the code into the app.

25. **[L] Join page is dark-only and duplicates the Google Fonts CDN.**
    `resources/views/join.blade.php:8-10` — hard-coded dark palette (no `prefers-color-scheme`, ignores the `inv_display_mode` cookie used everywhere else) and another fonts.googleapis.com dependency (see finding 7).

i18n sweep: EN/NL JSON coverage checked for enum labels (storage types, roles, colors, icons) and toast/undo strings — no gaps found beyond finding 16 (hard-coded reset-password errors).

## Iteration 5 — reset-password views, landing, tenancy middleware, guard wiring

26. **[M] Both password-reset views are entirely un-localized and ignore the theme system.**
    `resources/views/auth/reset-password.blade.php` and `reset-password-success.blade.php` — `lang="en"` hard-coded, zero `__()` calls (a Dutch user who toggled NL gets a full-English page despite the route sitting behind `inventory.locale`), dark palette only (no `prefers-color-scheme`, no `inv_display_mode` cookie), plus another Google Fonts CDN load. The rest of the surface was localized in GAP-6; these two views were missed.

27. **[L] Landing page honors `prefers-color-scheme` but ignores the user's explicit light/dark toggle.**
    `resources/views/landing.blade.php:23` — no `data-theme` stamping from the `inv_display_mode` cookie, so a signed-in user who chose light mode in the app pages gets OS-preference on the landing/marketing page. Cosmetic inconsistency.

Checked and clean this iteration: `EnsureHouseholdMember` (guard resolution is correct — `auth:inventory` sets the request's default guard before it runs), broadcasting channel auth (dual-guard, membership-scoped), landing i18n (fully keyed), guard separation (`inventory` session guard vs host `web`).

## Iteration 6 — shared form requests, Reorderer/Restorer, password-reset email

28. **[L] Reorderer validation messages are hard-coded English.**
    `src/Support/Reorderer.php:38,67` — 'The list must contain every location…' surfaces verbatim in the web `.error` flash on the non-JS reorder path (and in the API), untranslated for NL users. (The Alpine path masks it with the generic localized toast.)

29. **[L] Password-reset email is English-only.**
    `resources/views/emails/password-reset.blade.php` — `lang="en"`, no `__()`; NL users get an English email even after GAP-6's NL i18n pass. (Locale at send time would need to come from Accept-Language of the requesting client or a stored preference — worth a decision, logging as a gap.)

Checked and clean this iteration: `ReorderRequest` (bounded, distinct, int-cast), `UpdateHouseholdRequest`, `DeleteShelfRequest`/`DeleteLocationRequest` (strategy-required semantics, optional batch id), `Reorderer` (complete-set + household scoping, transactional), `Restorer` statuses (web controller maps all three to localized copy).
