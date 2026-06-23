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
`config/inventory.php`: `'domain' => env('INVENTORY_DOMAIN', 'inventory.scuttle.dev')` (Q-6: confirm parent domain).

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
No standalone deploy. Ships with sd-admin (DigitalOcean d051). Set `INVENTORY_DOMAIN`
and DNS for `inventory.{domain}` at infra step.
