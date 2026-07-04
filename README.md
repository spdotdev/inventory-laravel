# inventory-laravel

Laravel backend for the **Inventory** product — shipped as the Composer package
`spdotdev/inventory`: a **headless API + marketing landing page** mounted into a host
Laravel app (sd-admin) via host-based routing. Android is the sole API client.

> Status: **functionally-complete MVP, CI-green.** Auth (Sanctum + Google), the full
> `inventory_*` schema + models, `household.member` tenancy, resource CRUD
> (locations/shelves/products) + add/remove/move + image upload, search, password reset,
> client-error intake, the admin API, an MCP server, and the `inventory:household:*` commands
> are all in place — gated by Pint + Larastan + PHPUnit (SQLite + a real-MySQL CI job). See
> [`ROADMAP.md`](ROADMAP.md) for what's next and [`BACKLOG.md`](BACKLOG.md) for shipped history.

## Install (into a host Laravel app)

```bash
composer require spdotdev/inventory
```

The `InventoryServiceProvider` is auto-discovered. Optionally publish the config:

```bash
php artisan vendor:publish --tag=inventory-config
```

## Configuration

| Env | Default | Purpose |
| --- | --- | --- |
| `INVENTORY_DOMAIN` | the host app's own domain (`APP_URL` host) | Domain the landing page (`/`) and API (`/api/v1`) answer on. Set to a dedicated subdomain (e.g. `inventory.scuttle.dev`) to split it out. |
| `INVENTORY_GOOGLE_CLIENT_IDS` | _(empty)_ | Comma-separated Google OAuth client IDs. Google ID tokens posted to `/api/v1/auth/google` are accepted only if their `aud` matches one. Empty skips the audience check (set it in production). |

## What's mounted

- `GET /` — Frost-styled "coming soon" landing page (`inventory.landing`).
- `GET /api/v1/health` — JSON liveness/version probe (`inventory.api.health`).

Both are host-based routed on `config('inventory.domain')`.

## Development

```bash
composer install
make install-hooks   # optional: pre-push runs style + tests locally
composer test        # PHPUnit (orchestra/testbench, in-memory)
composer style       # Pint --test
composer stan        # Larastan (level 5)
```

CI runs the same three gates on push/PR (`.github/workflows/ci.yml`), plus a security
audit and secret scan.

## Spec

Authoritative product spec lives in
[`inventory-docs`](https://github.com/spdotdev/inventory-docs); package-specific notes in
[`CLAUDE.md`](CLAUDE.md) and [`docs/backend-plan.md`](docs/backend-plan.md).

## License

[MIT](./LICENSE)
