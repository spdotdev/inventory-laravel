# Web Google sign-in (redirect flow) — design

**Date:** 2026-07-11 · **Status:** approved (user: "do all for me") · **Repo:** inventory-laravel

## Goal

"Continue with Google" on the Phase-2 web UI's /login and /register pages, closing the
last ROADMAP item. The API's ID-token flow (`POST /api/v1/auth/google`) is untouched.

## Decision

Hand-rolled **server-side authorization-code (redirect) flow** — no Socialite, no JS.
Chosen over the Google Identity Services JS button because it keeps the web UI zero-JS,
reuses the existing `GoogleIdTokenVerifier` + account-linking logic nearly verbatim, and
has a clean CSRF story (session `state` param); the GIS button would introduce
third-party JS plus a CSRF-exempt credential POST for marginal gain.

## External config (done 2026-07-11)

- GCP project `scuttle-inventory`, new **web** OAuth client **"Inventory Web"**
  (`758637503304-6q4tf85cdcmd63qqgike0on2pmg9m4jr.apps.googleusercontent.com`), authorized
  redirect URI `https://inventory.scuttle.dev/auth/google/callback`. Dedicated client kept
  separate from the "Inventory Android" web-type client (its two secret slots were full
  and unreadable; Google no longer displays existing secrets).
- Server env (d051): `INVENTORY_GOOGLE_WEB_CLIENT_ID` + `INVENTORY_GOOGLE_WEB_CLIENT_SECRET`.
  The secret lives only in GCP + the server `.env` — never in the repo.

## Flow

1. `GET /auth/google` → generate `state` (random 40), store in session, 302 to
   `https://accounts.google.com/o/oauth2/v2/auth` with
   `client_id`, `redirect_uri`, `response_type=code`, `scope=openid email profile`, `state`.
2. Google consent → `GET /auth/google/callback?code=…&state=…`.
3. Callback validates `state` (pull-and-forget from session), exchanges `code` at
   `https://oauth2.googleapis.com/token` (client id + secret, `grant_type=authorization_code`),
   takes the returned **`id_token`** and runs it through the existing
   `GoogleIdTokenVerifier` — same audience allowlist machinery, with the web client id
   added to the accepted `aud` values.
4. Verified claims → shared account-linking (match `google_id`, fall back to verified
   email, else create) → `Auth::guard('inventory')->login($user, remember: true)` +
   session regenerate → `redirect()->intended(/app/households)`.
5. Any failure (state mismatch, Google `error` param, exchange failure, invalid token)
   → back to /login with a generic error flash. No user enumeration, no detail leak.

## Components

- `config/inventory.php` → `google.web.client_id` / `google.web.client_secret` (new envs
  above). Feature is **enabled only when both are set**; otherwise the routes 404 and the
  buttons don't render (mirrors the fail-closed posture of the verifier).
- `src/Auth/GoogleAccountLinker.php` — **extracted** from `Api\AuthController::google`:
  claims → User (google_id match → email match → create; email lowercased per W13;
  avatar backfill). API controller and web controller both call it; behavior unchanged.
- `src/Http/Controllers/Web/WebGoogleAuthController.php` — `redirect()` + `callback()`
  as above. Code→token exchange via the `Http` facade (consistent with
  `GoogleTokenInfoVerifier`; testable with `Http::fake`).
- `InventoryServiceProvider` — append `google.web.client_id` to the verifier's allowed
  `aud` list.
- `routes/web.php` — `GET /auth/google` + `GET /auth/google/callback`, guest routes on the
  inventory domain, `throttle:inventory-auth` (per-IP layer applies; there is no email input).
  `redirect_uri` is generated from the **named route** so it stays on `inventory.domain`
  (the X3 password-reset lesson: never build these from `APP_URL`).
- `login.blade.php` / `register.blade.php` — a "Continue with Google" link-button
  (plain `<a>`, Frost-styled, EN+NL via the existing web lang files), rendered only when
  the feature is enabled.

## Testing (critical paths)

`Http::fake` both Google endpoints. Feature tests: happy path signs in and creates a
user; email match links Google identity to an existing password account; state mismatch
rejected (no session, no user created); Google error param → login with flash; feature
unconfigured → 404; token-exchange failure → flash, no 500. Existing API auth tests
guard the extraction refactor. Pint + Larastan + PHPUnit green before ship.

## Out of scope

Web logout of Google (we only consume identity), refresh tokens (none requested —
`access_type` stays default `online`), PKCE (confidential client with secret; state
covers CSRF), and any change to `/api/v1`.
