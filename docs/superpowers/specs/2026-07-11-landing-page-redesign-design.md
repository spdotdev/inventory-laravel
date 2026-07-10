# Landing page redesign — design

**Date:** 2026-07-11 · **Status:** approved (brainstorm 2026-07-11)
**Replaces:** the Frost "coming soon" placeholder at `/` on `inventory.{domain}`.

## Goal

A **hybrid, marketing-first** landing page: it sells the product with a real value
proposition and stylized app visuals, and funnels visitors into the shipped web
account/household UI as the actionable CTA. It stays **honest about availability** —
the Android app is a private preview (debug builds, no store listing), so the page
teases it without a download link or launch date.

Decisions locked during brainstorming:

| Question | Decision |
|---|---|
| Page goal | Hybrid, marketing-first (hero + features; web sign-in as secondary CTA) |
| Visuals | CSS-built phone mockups in Frost tokens — no images, no JS |
| Android CTA | "Private preview / coming to Google Play" teaser, **no download link** |
| Localization | EN + NL via package lang files, `Accept-Language` negotiation |

## Page structure (top → bottom)

1. **Header** — slim static bar. "Inventory" wordmark left; "Sign in" link +
   "Create account" button right, targeting the existing web routes
   (`inventory.web.login.show`, `inventory.web.register.show`).
2. **Hero** — pulsing badge "Android app in private preview"; headline in the spirit
   of "Know what you have, wherever you keep it"; one-sentence lead (shared household
   inventory, always in sync). Primary CTA **Create a free account** → `/register`;
   quiet CTA **Sign in** → `/login`. Beside the text (stacked below on mobile): a
   **CSS phone mockup** of the dashboard (stat cards, per-location bars, a
   "running low" list).
3. **Feature grid** — five emoji-iconed cards: shared households (join by code/QR);
   storage tree (locations → shelves → products, incl. search); barcode scanning;
   running-low warnings; live sync across devices.
4. **How it works** — three numbered steps: create your household → lay out storages
   and add products → invite the family (changes appear live). Beside it, a second
   smaller CSS mockup: location detail with shelf tabs and a running-low chip.
5. **Preview band** — "The Android app is in private preview — coming to Google Play.
   The web app works everywhere today." + the create-account CTA repeated.
6. **Footer** — "A Scuttle Development project" (link), as today.

**Tone:** honest — no download links, no dates, no store badges.

## Visual & interaction rules

- Frost identity: existing CSS custom-property tokens (accent `#7dd3fc`, dark/light
  via `prefers-color-scheme`), Plus Jakarta Sans, frosted cards.
- The badge pulse animation is wrapped in `@media (prefers-reduced-motion: no-preference)`.
- Fully responsive: hero and how-it-works stack on narrow screens; the feature grid
  collapses 3 → 2 → 1 columns.
- **No JavaScript.** Everything is static HTML/CSS.
- Page stays indexable; `<title>`, meta description, and og tags localized.

## Implementation

All changes in `inventory-laravel`; routes, `/api/v1`, web UI views, and the deploy
pipeline are untouched.

| File | Change |
|---|---|
| `resources/views/landing.blade.php` | Rewritten one-pager; CSS inline in the view (repo convention) |
| `resources/views/landing/_mock-dashboard.blade.php` | Hero phone mockup (package-internal partial) |
| `resources/views/landing/_mock-location.blade.php` | Location-detail mockup partial |
| `lang/en/landing.php`, `lang/nl/landing.php` | All user-visible strings incl. title/meta/og |
| `src/InventoryServiceProvider.php` | `loadTranslationsFrom(__DIR__.'/../lang', 'inventory')` in `boot()` |
| `src/Http/Controllers/LandingController.php` | `App::setLocale($request->getPreferredLanguage(['en', 'nl']) ?? 'en')` before render |

- Strings accessed as `__('inventory::landing.<key>')`.
- Locale negotiation is scoped to the landing request only; the web app UI stays English.
- Mockup partials are miniatures, not pixel copies — acceptable drift is the accepted
  trade-off of the no-image approach.

## Testing (critical paths only)

Extend the existing landing test:
1. `GET /` → 200, contains the EN hero headline.
2. `GET /` with `Accept-Language: nl` → NL headline.
3. `GET /` with `Accept-Language: de` → falls back to EN.

No other coverage — static page, no state.

## Out of scope

Google sign-in on the web (separate ROADMAP item), any change to the join page,
screenshots/store badges, analytics, cookie banners (no cookies added).
