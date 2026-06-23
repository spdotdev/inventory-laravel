# inventory-laravel — backend planning slice

> Backend-specific planning. The shared, authoritative spec lives in
> [`inventory-docs`](https://github.com/spdotdev/inventory-docs):
> `planning/project-brief.md`, `specs/data-model.md`, `specs/api-contract.md`.
> This file covers only what's specific to building the Laravel package.

## Identity
- Package name: **`spdotdev/inventory`**
- Namespace: **`Spdotdev\Inventory\`**, service provider `InventoryServiceProvider` (auto-discovered)
- Host app: **sd-admin** (Laravel 13 + Filament 5, MySQL) — `composer require spdotdev/inventory`
- Reference implementation to copy: **`spdotdev/scuttle-dev`** (host-based routing, publishable config + assets)

## Routing model
```php
// routes/web.php — landing page
Route::domain(config('inventory.domain'))->middleware('web')->group(function () {
    Route::get('/', [LandingController::class, 'index'])->name('inventory.landing');
});

// routes/api.php — headless API
Route::domain(config('inventory.domain'))->prefix('api/v1')->middleware('api')->group(function () {
    // auth, households, locations, shelves, products — see specs/api-contract.md
});
```
`config/inventory.php` (Q-6 resolved — default to the host app's own domain, override via env):
```php
return [
    // Defaults to the host app's own domain; set INVENTORY_DOMAIN to serve on a
    // dedicated subdomain (e.g. inventory.scuttle.dev).
    'domain' => env('INVENTORY_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
];
```
In the service provider, apply `Route::domain(config('inventory.domain'))` for both route
groups. Since the default resolves to the app's own host, no dedicated subdomain is required
to run — it just works on the host app's domain until `INVENTORY_DOMAIN` is set.

## Dependencies to add
- `laravel/sanctum` — bearer tokens for the Android client
- `laravel/socialite` — Google sign-in (verify the Android-supplied Google ID token, stateless)
- dev: `orchestra/testbench`, `larastan/larastan`, `laravel/pint`, `phpunit/phpunit`

## Auth implementation notes
- `inventory_users` uses `HasApiTokens`; `password` nullable, `google_id` unique nullable, `avatar_url` nullable.
- `/auth/register`, `/auth/login` → hash check → issue Sanctum token.
- `/auth/google` → receive Google **ID token** from Android → verify via Socialite stateless →
  find-or-create by `google_id` then `email` → issue Sanctum token.
- `/auth/logout` → revoke the current access token.

## Tenancy
- `EnsureHouseholdMember` middleware aliased `household.member`.
- Chain: `auth:sanctum → household.member → resource policy`.
- Route-model binding scoped to `{household}`; out-of-tenant resources return **404** (don't leak existence).

## Artisan commands (D-032)
- `inventory:household:create {name}` — create a household (prints join code).
- (room to grow: add member, regenerate join code, list households.)

## Landing page (D-028)
- `LandingController@index` renders a Frost-styled Blade "coming soon" page.
- Hints: Android app, inventory management. **Does not** disclose features/roadmap.
- Assets under `public/`, publishable to `public/vendor/inventory` (mirror scuttle-dev).

## Testing (critical paths only)
Tenancy isolation, auth (email/password + Google find-or-create), stock floor at 0,
cascade deletes, join-by-code, domain routing resolves landing vs api.

## Build order
See `CLAUDE.md` → "Build order". Start: skeleton + migrations + tenancy middleware.

## Deploy
No standalone deploy. Ships with sd-admin (DigitalOcean d051). By default it serves on
sd-admin's own domain; **optionally** set `INVENTORY_DOMAIN` (+ DNS) at the infra step to
move it onto a dedicated subdomain like `inventory.scuttle.dev`.
