# inventory-laravel

Laravel backend for the **Inventory** product — shipped as the Composer package
`spdotdev/inventory`: a **headless API + marketing landing page** mounted into a host
Laravel app (sd-admin) via host-based routing. Android is the sole API client.

> Status: **skeleton**. Service provider, host-based route groups, landing page, and the
> `/api/v1/health` probe are in place. Auth (Sanctum + Google), the `inventory_*` schema,
> and the resource CRUD land in the next steps — see [`ROADMAP.md`](ROADMAP.md).

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
