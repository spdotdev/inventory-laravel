# Data Model — Inventory (canonical)

> Single source of truth for the schema. Both `inventory-laravel` (owns/migrates it)
> and `inventory-android` (consumes it via the API) depend on this file.

## Naming & conventions

- The product is **Inventory** — a general-purpose, multi-household stock manager.
  Freezer / fridge / pantry are *examples* of what it manages, not its identity.
- `inventory-laravel` ships as a **Composer package mounted into a host Laravel app**
  (sd-admin). To avoid colliding with the host app's own tables, **every
  package-owned table is prefixed `inventory_`**.
- Engine: **MySQL** (the host app's default connection). All FKs `ON DELETE CASCADE`
  down the location → shelf → product tree; a soft delete is an `UPDATE` and never
  fires them. **Soft deletes on the hierarchy** (`inventory_storage_locations`,
  `inventory_shelves`, `inventory_products` carry `deleted_at` +
  `deletion_batch_id`, added 2026-07-13 so deleting a container is recoverable
  rather than silently destroying everything inside it). `quantity` floors at 0.

## Tables

```
inventory_users
  id, name, email (unique), password (nullable — Google-only users have none),
  google_id (nullable, unique), avatar_url (nullable),
  email_verified_at (nullable), remember_token, created_at, updated_at

inventory_households
  id, name, join_code (unique),
  color (nullable string — palette key, see HouseholdColor enum),
  icon (nullable string — icon key, see HouseholdIcon enum),
  created_at, updated_at
  -- join_code drives the invite link + QR (D-026)
  -- color/icon: Phase-2 user-chosen theme; null = client derives from id

inventory_household_user                      -- membership pivot
  household_id, user_id, joined_at
  -- composite PK; NO role column (all members equal, D-017)

inventory_storage_locations
  id, household_id (FK CASCADE), name,
  type ENUM(freezer|fridge|pantry|other), position (unsigned int, default 0 —
    manual drag order; added 2026-07-13, same contract as inventory_shelves.position),
  is_system (boolean, default false; added 2026-07-13 — unused today, reserved so a
    future household-level holding area doesn't need another migration against a live
    table; not yet exposed via the API),
  created_at, updated_at,
  deleted_at (nullable — soft delete, added 2026-07-13),
  deletion_batch_id (nullable uuid, indexed — groups every row killed by one
    user gesture so Undo can restore it as a unit; minted client-side)

inventory_shelves
  id, location_id (FK CASCADE), name, position,
  is_system (boolean, default false; added 2026-07-13 — marks the per-location
    "Unsorted" shelf: lazily created on first use, unrenameable, unmovable, always
    sorted last; see `api-contract.md` § *Deleting a location or shelf*),
  created_at, updated_at,
  deleted_at (nullable — soft delete, added 2026-07-13),
  deletion_batch_id (nullable uuid, indexed — see inventory_storage_locations)

inventory_products
  id, shelf_id (FK CASCADE), name, quantity (>= 0),
  description (nullable text),
  code (nullable string, max 100 — free-form product code / scanned barcode),
  is_mandatory (boolean, default false — "should always be stocked"; qty 0 = missing),
  is_starred (boolean, default false — user-toggled favorite/pin; added 2026-07-13,
    no server-side sort/filter semantics, just storage + passthrough),
  image_url (nullable string — absolute URL, set by POST .../products/{id}/image),
  low_stock_threshold (nullable unsigned int, >= 1 — "running low" warning at
    quantity <= threshold; NULL = feature off for the product; Phase 2, 2026-07-10),
  created_at, updated_at,
  deleted_at (nullable — soft delete, added 2026-07-13),
  deletion_batch_id (nullable uuid, indexed — see inventory_storage_locations)
  -- quantity 0 = out of stock; row retained for easy re-add

inventory_client_errors                       -- remote client crash/error intake
  id, device_id, error_code, message (nullable text), app_version (nullable),
  created_at (index: device_id, created_at)
  -- written by the unauthenticated, throttled POST /errors; pruned by
  --   inventory:client-errors:prune (retention: inventory.client_errors_retention_days)

inventory_password_resets                     -- password-reset tokens
  email (indexed), token (hashed), created_at
  -- 60-minute TTL enforced on use; row consumed on a successful reset

personal_access_tokens (Sanctum)             -- shared/global; tokenable_type
                                                 distinguishes the inventory User model
```

## Indexes (performance)

- `inventory_storage_locations.household_id`
- `inventory_shelves.location_id`
- `inventory_products.shelf_id`
- `inventory_client_errors.(device_id, created_at)` — supports per-device lookups + prune
- `inventory_products.name` — add a **FULLTEXT** index if/when `LIKE` search
  feels slow. Not needed at expected scale (dozens–thousands of products/household).

## Tenancy rule

Everything belongs to a **household**, never directly to a user. Membership is
enforced at the API boundary (`household.member` middleware) *before* any resource
access. Child queries are scoped by the validated `household_id`.

## Relationship diagram

```
inventory_users >──< inventory_households          (via inventory_household_user)
inventory_households ──< inventory_storage_locations ──< inventory_shelves ──< inventory_products
```
