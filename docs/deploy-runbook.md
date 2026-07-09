# Deploy runbook — Inventory

How to ship the `spdotdev/inventory` backend package into the **sd-admin** host app and
make it serve at `inventory.{domain}`. The package is headless API + a landing page,
mounted via host-based routing — it has no standalone deploy of its own.

> Status (2026-06-24): package integrated into sd-admin in **PR #1**
> (`feat/mount-inventory-package`). Steps 1–2 below are done in that PR; the rest run on
> the server once it's provisioned.

---

## Prerequisites

- **sd-admin** deployed on the DigitalOcean server (**d051** — pending provisioning).
- **MySQL** reachable by sd-admin (the package ships migrations onto the host's default
  connection).
- Server PHP **8.4+** with `ext-intl` (Filament needs it) — matches sd-admin's lock.
- A **GitHub token** available to Composer on the server, so it can fetch the package
  from its VCS repo:
  ```bash
  composer config --global github-oauth.github.com <token>
  ```
  (`spdotdev/inventory-laravel` is public, but the token avoids API rate limits.)

---

## 1. Install the package (done in PR #1)

`sd-admin/composer.json` already has the VCS repository + requirement:

```json
"repositories": [{ "name": "inventory", "type": "vcs", "url": "https://github.com/spdotdev/inventory-laravel" }],
"require":      { "spdotdev/inventory": "^0.1" }
```

On the server, a normal install picks it up (and Sanctum, pulled transitively):

```bash
cd /path/to/sd-admin
composer install --no-dev --optimize-autoloader
```

`InventoryServiceProvider` auto-discovers — no manual registration.

> To bump the package later: tag a new `inventory-laravel` release, then
> `composer update spdotdev/inventory` (do **not** use `--with-all-dependencies` — it
> needlessly bumps unrelated deps).

## 2. Configure environment (`sd-admin/.env`)

```dotenv
# Host the landing page (/) and API (/api/v1) answer on.
# Leave blank to use the app's own APP_URL host; set a subdomain to split it out:
INVENTORY_DOMAIN=inventory.scuttle.dev

# Comma-separated Google OAuth client IDs accepted by /api/v1/auth/google
INVENTORY_GOOGLE_CLIENT_IDS=
```

## 3. Migrate the database

Creates the `inventory_*` tables **and** Sanctum's `personal_access_tokens`:

```bash
php artisan migrate --force
```

Idempotent and additive — the package's tables are `inventory_`-prefixed, so they never
collide with sd-admin's own tables.

## 4. Publish assets (optional)

The landing page is self-contained (inline styles), so this is only needed if/when the
package ships static assets:

```bash
php artisan vendor:publish --tag=inventory-assets   # -> public/vendor/inventory
php artisan vendor:publish --tag=inventory-config   # -> config/inventory.php (to override)
```

## 5. Refresh caches

After any `.env` or route change:

```bash
php artisan config:clear && php artisan config:cache
php artisan route:clear  && php artisan route:cache
```

## 6. DNS + TLS

- **DNS:** add an `A` record for `inventory.<domain>` → the d051 server IP.
- **TLS:** the cert must cover the subdomain (wildcard `*.<domain>`, or add it to the
  Let's Encrypt SAN list). Host-based routing keys on `config('inventory.domain')`, so the
  request `Host` header must match `INVENTORY_DOMAIN`.

## 7. Smoke test

```bash
curl -s https://inventory.<domain>/            # Frost "coming soon" landing page (HTML)
curl -s https://inventory.<domain>/api/v1/health
# -> {"name":"inventory","api":"v1","status":"ok"}
```

Then exercise auth end-to-end:

```bash
curl -s -X POST https://inventory.<domain>/api/v1/auth/register \
  -H 'Accept: application/json' \
  -d 'name=Test&[email protected]&password=secret-password'
# -> { "user": {...}, "token": "..." }
```

## 8. Point the Android client at it

In `inventory-android/app/build.gradle.kts`, set the release `BASE_URL` to
`https://inventory.<domain>/api/v1/` (trailing slash required). For Google sign-in, add a
real Google OAuth client ID on both sides (`INVENTORY_GOOGLE_CLIENT_IDS` on the server +
the Android Credential Manager config).

---

## Rollback

```bash
# Remove the package (app keeps running without the inventory routes):
composer remove spdotdev/inventory
php artisan config:cache && php artisan route:cache

# The inventory_* tables can be left in place (harmless) or dropped manually.
```

Or revert the sd-admin merge commit and `composer install`.

---

## Open items (tracked elsewhere)

- DO server **d051** provisioning + the CD pipeline (sd-admin / d0-admin).
- Final `INVENTORY_DOMAIN` choice (Q-6: defaults to the host APP_URL domain).
- A **Google OAuth client ID** to enable `/api/v1/auth/google`.
