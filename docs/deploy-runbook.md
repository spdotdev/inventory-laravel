# Deploy runbook — Inventory

How the `spdotdev/inventory` package ships inside the **sd-admin** host app and serves
at `inventory.{domain}`. The package is a headless API + web UI + landing page mounted
via host-based routing — it has **no standalone deploy of its own**; it rides sd-admin's
pipeline.

> **Status: live in production** at `https://inventory.scuttle.dev` on DigitalOcean
> **d051** since 2026-07-10 (package v0.1.5, sd-admin auto-deploy with per-push
> approval). Reverb live updates are configured and verified. The sections below are
> the operating procedure; §1–§6 describe one-time setup that is already in place.

---

## Release procedure (routine)

Releases are **pulled by the host, not pushed from here**:

1. Merge to `main` in `inventory-laravel`; CI must be green.
2. Tag a release: `git tag v0.x.y && git push origin v0.x.y`.
3. In **sd-admin**, bump the pin and lock:
   ```bash
   composer update spdotdev/inventory        # do NOT use --with-all-dependencies
   git commit -am "chore: bump spdotdev/inventory to v0.x.y" && git push
   ```
4. sd-admin's CI builds and deploys to d051 (deploy requires per-push approval).
5. Run the smoke tests (§7).

The deploy runs `composer install`, `php artisan migrate --force`, and cache refreshes
on the server — package migrations are additive and `inventory_`-prefixed, so they never
collide with host tables.

---

## 1. Host integration (in place)

`sd-admin/composer.json` carries the VCS repository + requirement:

```json
"repositories": [{ "name": "inventory", "type": "vcs", "url": "https://github.com/spdotdev/inventory-laravel" }],
"require":      { "spdotdev/inventory": "^0.1" }
```

`InventoryServiceProvider` auto-discovers — no manual registration. Server
prerequisites: PHP 8.4+ with `ext-intl`, MySQL on the host's default connection, and a
GitHub token for Composer (`composer config --global github-oauth.github.com <token>` —
the repo is public, but the token avoids API rate limits).

## 2. Environment (`sd-admin/.env` on d051)

```dotenv
# Host the whole package answers on. Blank = the app's own APP_URL host.
INVENTORY_DOMAIN=inventory.scuttle.dev

# Comma-separated Google OAuth client IDs accepted by /api/v1/auth/google.
# Fails closed: empty rejects all Google sign-ins.
INVENTORY_GOOGLE_CLIENT_IDS=<android-oauth-client-id>

# Web UI "Continue with Google" (server-side redirect flow, v0.1.9+). The
# "Inventory Web" GCP client; its registered redirect URI must byte-match
# https://<INVENTORY_DOMAIN>/auth/google/callback. Fails closed: the
# /auth/google routes 404 and the buttons hide while either value is unset.
INVENTORY_GOOGLE_WEB_CLIENT_ID=<web-oauth-client-id>
INVENTORY_GOOGLE_WEB_CLIENT_SECRET=<web-oauth-client-secret>

# Static bearer token for /api/v1/admin/* and /mcp. Empty disables the admin surface.
INVENTORY_ADMIN_TOKEN=<long-random-string>

# Live updates — Reverb (Pusher protocol). Without these the broadcast
# events are silent no-ops and clients fall back to pull-to-refresh.
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=…
REVERB_APP_KEY=…
REVERB_APP_SECRET=…
REVERB_HOST=…
REVERB_PORT=…
```

Optional tuning: `INVENTORY_RL_*` (rate limits), `INVENTORY_CLIENT_ERRORS_RETENTION_DAYS`,
`INVENTORY_IMAGE_DISK` / `INVENTORY_IMAGE_MAX_KB`, `INVENTORY_ANDROID_APP_URL` — see
`config/inventory.php` for the documented defaults.

## 3. Database

```bash
php artisan migrate --force
```

Creates the `inventory_*` tables **and** Sanctum's `personal_access_tokens`. Idempotent
and additive.

## 4. Assets & caches

```bash
php artisan vendor:publish --tag=inventory-assets   # only if the package ships static assets
php artisan vendor:publish --tag=inventory-config   # to override config locally
php artisan config:clear && php artisan config:cache
php artisan route:clear  && php artisan route:cache
```

Product images use the `public` disk by default — the host must have run
`php artisan storage:link`.

## 5. DNS + TLS (in place)

- `A` record for `inventory.scuttle.dev` → d051.
- TLS cert covers the subdomain. Host-based routing keys on
  `config('inventory.domain')`, so the request `Host` header must match
  `INVENTORY_DOMAIN`.

## 6. Reverb / websockets (in place)

Reverb runs as a container in the sd-admin stack; nginx proxies websocket upgrades
through to it (Caddy → nginx → Reverb). Verified end-to-end 2026-07-10: 101 Switching
Protocols on the websocket handshake and a broadcast job processed clean.

**Hard-won host gotchas** (both fixed, kept here so they aren't re-learned):
- The nginx catch-all server block needs `default_server` — without it, unmatched hosts
  fell through to the crm block.
- A single-file bind mount for nginx config served **stale config after deploys**; use
  a directory mount instead.

## 7. Smoke tests (after every deploy)

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://inventory.scuttle.dev/          # 200 landing
curl -s https://inventory.scuttle.dev/api/v1/health
# -> {"name":"inventory","api":"v1","status":"ok","database":"ok"}
curl -s -o /dev/null -w '%{http_code}\n' https://inventory.scuttle.dev/login     # 200 web UI
curl -s -o /dev/null -w '%{http_code}\n' https://inventory.scuttle.dev/register  # 200 web UI
```

Then exercise auth end-to-end (register issues a token) and — after any
proxy/Reverb/env change — confirm the websocket handshake returns
**101 Switching Protocols** on the Reverb endpoint.

## 8. Android client pairing

`inventory-android` release builds point `BASE_URL` at
`https://inventory.scuttle.dev/api/v1/` (trailing slash required). Google sign-in needs
the same OAuth client ID on both sides: `INVENTORY_GOOGLE_CLIENT_IDS` on the server and
the Credential Manager config in the app.

---

## Rollback

Two levels, cheapest first:

```bash
# 1. Pin sd-admin back to the previous package tag and redeploy:
composer require spdotdev/inventory:v0.x.(y-1)
git commit -am "revert: pin spdotdev/inventory back to v0.x.(y-1)" && git push

# 2. Remove the package entirely (host keeps running without the inventory routes):
composer remove spdotdev/inventory
php artisan config:cache && php artisan route:cache
```

Migrations are additive — the `inventory_*` tables can be left in place (harmless) or
dropped manually. There is no automated down-migration in production.
