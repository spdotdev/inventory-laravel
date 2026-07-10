# inventory-laravel

Laravel backend for the **Inventory** product — a private, multi-user, multi-household
home-stock manager. Ships as the Composer package `spdotdev/inventory`, mounted into a
host Laravel app (sd-admin) via host-based routing. It serves a versioned headless API
(consumed by the [Android client](https://github.com/spdotdev/inventory-android)), a
web account/household UI, a landing page, and an admin surface — all on
`config('inventory.domain')`.

> Status: **live in production** at `inventory.scuttle.dev` (deployed with the sd-admin
> host app). Functionally-complete MVP + Phase 2: auth (Sanctum + Google), the full
> `inventory_*` schema, `household.member` tenancy, resource CRUD + stock actions +
> image upload, search, password reset, live updates (Reverb broadcasting), the web UI,
> client-error intake, the admin API + MCP servers, and Artisan commands. Gated by
> Pint + Larastan + PHPUnit (SQLite + real-MySQL CI jobs). See [`ROADMAP.md`](ROADMAP.md)
> for what's next and [`BACKLOG.md`](BACKLOG.md) for shipped history.

## What's mounted

Everything is host-based routed on `config('inventory.domain')` (defaults to the host
app's own domain; see Configuration):

| Surface | Routes | Notes |
| --- | --- | --- |
| Landing page | `GET /` | Frost-styled "coming soon" page |
| Web app | `/login`, `/register`, `/reset-password`, `/join/{code}`, `/app/*` | Session-guarded account/household/inventory UI (`auth:inventory`) |
| API | `/api/v1/*` | Headless REST+JSON, Sanctum bearer tokens — the contract in [`docs/specs/api-contract.md`](docs/specs/api-contract.md) |
| Live updates | `POST /api/v1/broadcasting/auth` | Pusher-protocol channel auth for the private `inventory.household.{id}` channel (Reverb on the host) |
| Admin API | `/api/v1/admin/*` | Static-token operator surface (users + households) |
| Admin MCP | `/mcp` | HTTP MCP server (`src/Mcp/`), same tools as [`inventory-mcp`](https://github.com/spdotdev/inventory-mcp) |
| Health | `GET /api/v1/health` | JSON liveness/version/database probe |

## Install (into a host Laravel app)

```bash
composer require spdotdev/inventory
```

The `InventoryServiceProvider` is auto-discovered; migrations run with the host's
`php artisan migrate` (all tables are `inventory_`-prefixed). Optionally publish the
config:

```bash
php artisan vendor:publish --tag=inventory-config
```

## Configuration

All settings are env-driven (see `config/inventory.php` for the documented source of
truth):

| Env | Default | Purpose |
| --- | --- | --- |
| `INVENTORY_DOMAIN` | host app's own domain (`APP_URL` host) | Domain the whole package answers on. Set to a dedicated subdomain (e.g. `inventory.scuttle.dev`) to split it out. |
| `INVENTORY_GOOGLE_CLIENT_IDS` | _(empty)_ | Comma-separated Google OAuth client IDs. `/api/v1/auth/google` accepts an ID token only if its `aud` matches one. **Fails closed** — empty rejects all Google sign-ins. |
| `INVENTORY_ADMIN_TOKEN` | _(empty)_ | Static bearer token protecting `/api/v1/admin/*` and `/mcp`. Empty disables the admin surface entirely. |
| `INVENTORY_RL_AUTH_IDENTITY` / `INVENTORY_RL_AUTH_IP` | `10` / `30` | Per-minute rate limits on the unauthenticated auth endpoints (per email+IP / per IP). `0` disables a layer. |
| `INVENTORY_RL_JOIN_USER` | `8` | Per-minute join-by-code attempts per user (join-code guessing cap). |
| `INVENTORY_RL_ERRORS_DEVICE` | `20` | Per-minute client-error-intake posts per device+IP. |
| `INVENTORY_CLIENT_ERRORS_RETENTION_DAYS` | `30` | Retention for `inventory:client-errors:prune` (schedule it in the host app; `0` = keep forever). |
| `INVENTORY_IMAGE_DISK` / `INVENTORY_IMAGE_MAX_KB` | `public` / `5120` | Filesystem disk + max upload size for product photos. |
| `INVENTORY_ANDROID_APP_URL` | _(empty)_ | Install link shown by the `/join/{code}` web fallback. |

Live updates additionally require a broadcaster on the host (`BROADCAST_CONNECTION=reverb`
+ `REVERB_*`); without one the broadcast events are silent no-ops.

## Development

```bash
composer install
make install-hooks   # optional: pre-push runs style + tests locally
composer test        # PHPUnit (orchestra/testbench, in-memory)
composer style       # Pint --test
composer stan        # Larastan (level 5)
```

CI runs the same three gates on push/PR (`.github/workflows/ci.yml`) — on SQLite and on
a real MySQL service — plus a security audit and secret scan.

## Deployment

The package has **no standalone deploy** — it rides the sd-admin host app. Releases are
pulled, not pushed: tag a release here, bump the version pin in sd-admin's
`composer.json`, and sd-admin's pipeline ships it. The full procedure (env, migrations,
DNS/TLS, Reverb, smoke tests, rollback) is in
[`docs/deploy-runbook.md`](docs/deploy-runbook.md).

## Documentation

[`docs/`](docs/) is the canonical documentation home for the whole Inventory product —
specs (data model, API contract), product planning, and operations. Start at
[`docs/README.md`](docs/README.md). Package-specific engineering notes live in
[`CLAUDE.md`](CLAUDE.md) and [`docs/backend-plan.md`](docs/backend-plan.md).

## License

[MIT](./LICENSE)
