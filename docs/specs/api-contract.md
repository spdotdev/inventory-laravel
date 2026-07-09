# API Contract — Inventory (canonical)

> Single source of truth for the HTTP contract between `inventory-android` (client)
> and `inventory-laravel` (server). Versioned from day one; **backward compatible** —
> a shipped Android build updates on the user's schedule, not ours.
>
> **Status:** the core contract is implemented in `inventory-laravel` and CI-green.
> **Reconciled 2026-07-04** to the shipped API — product detail fields, the password-reset
> flow, the operator/admin surface, the health + client-error-intake endpoints, and
> rate-limit (429) responses are now documented below. Keep updating specs-first as the
> API evolves.

## Base

- **Host:** the package answers on `config('inventory.domain')`, which **defaults to the
  host app's own domain** (`APP_URL` host) and is overridable via `INVENTORY_DOMAIN`
  (e.g. a dedicated `inventory.scuttle.dev`).
- **Prefix:** `/api/v1`
- **Format:** REST + JSON.
- **Auth:** Laravel **Sanctum** bearer tokens. Email/password **and** Google sign-in
  both resolve to a Sanctum token the Android client stores and sends as
  `Authorization: Bearer <token>`.
- **Middleware chain:** `auth:sanctum` → `household.member({household})` → resource policy.

## Auth

```
POST   /api/v1/auth/register   { name, email, password }      -> { user, token }
POST   /api/v1/auth/login      { email, password }            -> { user, token }
POST   /api/v1/auth/google     { id_token }                   -> { user, token }
POST   /api/v1/auth/forgot-password { email }                 -> 200 always (see Password reset)
POST   /api/v1/auth/logout                                    -> revoke current token
```

The unauthenticated auth endpoints (`register`/`login`/`google`/`forgot-password`) are
**rate-limited** (per submitted email + IP, under a looser per-IP cap) and return **429**
when exceeded — see *Rate limiting*.

**Google flow:** Android performs native Google Sign-In, obtains a Google **ID
token**, and posts it to `/auth/google`. The server verifies the ID token via a
swappable `GoogleIdTokenVerifier` (default impl: Google's `tokeninfo` endpoint —
**not** Socialite, whose `userFromToken()` expects an OAuth *access* token, not an
ID token). Verification requires:
- a valid Google **issuer** (`accounts.google.com`);
- the **audience** (`aud`) to match a configured client ID — `INVENTORY_GOOGLE_CLIENT_IDS`.
  **Fails closed**: with no client IDs configured, all Google tokens are rejected;
- **`email_verified` = true** — this is what makes find-or-create-by-email safe (an
  attacker can't get Google to verify an email they don't control).

On success it finds-or-creates the `inventory_users` row (matching on `google_id` then
`email`) and returns a Sanctum token. Google-only users have a null `password`.

**Errors:** invalid/untrusted Google token → **401**. Validation failures (register/login)
→ 422. Logout requires `auth:sanctum`.

The `user` returned by register/login/google is the **User resource** (`UserResource`):

```
{ id, name, email,
  avatar_url | null }          # absolute URL of the Google profile picture; null for
                               # password-only accounts. Client field is required —
                               # a rename breaks deserialization.
```

Emails are **normalized to lowercase** at the boundary (register/login/Google), so
lookups are case-insensitive.

### Password reset

Email/password accounts can self-serve a reset (Google-only accounts have no password):

1. `POST /api/v1/auth/forgot-password { email }` — **always returns 200** (never reveals
   whether the email exists). If it matches a user, a single-use token (hashed at rest in
   `inventory_password_resets`) is stored and a link is emailed.
2. The link opens a **web** page on the inventory domain (not `/api/v1`):
   `GET /reset-password?token=&email=` → the reset form;
   `POST /reset-password { token, email, password, password_confirmation }`.
3. On success the password is updated, **all existing Sanctum tokens are revoked**, and the
   reset row is consumed. The token **expires after 60 minutes**; an expired or tampered
   token is rejected.

## Households & membership

```
GET    /api/v1/households                                     -> households the caller belongs to
POST   /api/v1/households                  { name }           -> create (creator auto-joins)
GET    /api/v1/households/{household}/invite                  -> { code, link }
                                                                 (client renders QR from link)
POST   /api/v1/households/join             { code }           -> join by code
DELETE /api/v1/households/{household}/leave                   -> leave self
GET    /api/v1/households/{household}/search?q=               -> matching products (see Search result)
```

**Household resource** (`HouseholdResource`) — returned by list/create/join:

```
{ id, name,
  join_code }                  # visible to members (all members are equal and may
                               # invite); this resource is only ever returned to members.
                               # Client field is required — a rename breaks deserialization.
```

The invite endpoint returns `{ code, link }` where `link` is
`https://{domain}/join/{code}` — a real page (Frost-styled) that shows the code and
points at the app, not just an app-only deep link.

**Search result** (`SearchResultResource`): each hit carries the display path **and** the
navigation IDs the client deep-links with:

```
{ id, name, quantity,
  location,                    # location name
  shelf,                       # shelf name
  path,                        # "location › shelf"
  household_id, location_id, shelf_id }   # nav target: household › location › shelf › product
```

The nav IDs are **required** — the Android client only makes a result tappable when
`household_id` and `shelf_id` are present.

## Resources (all under `/api/v1/households/{household}`)

```
       /locations[/{location}]                       CRUD   (type: freezer|fridge|pantry|other)
       /locations/{location}/shelves[/{shelf}]       CRUD
       /shelves/{shelf}/products[/{product}]         CRUD

POST   /products/{product}/add     { amount }        -> increment quantity (atomic)
POST   /products/{product}/remove  { amount }        -> decrement (atomic, floor 0)
POST   /products/{product}/move    { shelf_id }      -> relocate within the household
POST   /products/{product}/image   (multipart)       -> upload photo, sets image_url
```

`add`/`remove` apply an **atomic** quantity delta (1 ≤ `amount` ≤ 1,000,000); `remove`
floors at 0. `amount`/`quantity` are capped at 1,000,000 — an over-cap value is a **422**,
not a 500 (keeps the `unsignedInteger` column from overflowing).

**Location resource** (`LocationResource`):

```
{ id, name, type }             # type: freezer | fridge | pantry | other
```

**Shelf resource** (`ShelfResource`):

```
{ id, name,
  position,                    # server-assigned order (max+1 on create); index is
                               # orderBy('position'), so the client's tab/pager order is stable
  location_id }                # parent location; client field is required
```

### Product shape

Response (`ProductResource`):

```
{ id, shelf_id, name, quantity,
  description | null,          # free-form notes
  code | null,                 # free-form product code / barcode
  is_mandatory,                # bool; a mandatory item at quantity 0 = "missing"
  image_url | null }           # absolute URL of the product photo; null until one is uploaded
```

Create body (`POST …/products`): `name` (required, ≤50) + optional `quantity` (≥0),
`description`, `code` (≤100), `is_mandatory`. Update body (`PUT/PATCH …/products/{id}`):
the same fields, all optional. `image_url` is **not** settable via create/update — it is
managed solely by the image-upload endpoint below.

**Product image upload.** `POST …/products/{product}/image` — `multipart/form-data` with a
single `image` part (JPEG / PNG / WebP, ≤ `INVENTORY_IMAGE_MAX_KB`, default 5 MB). The file
is stored on the configured filesystem disk (`INVENTORY_IMAGE_DISK`, default `public`),
`image_url` is set to the file's absolute URL, and the updated `ProductResource` is
returned. Uploading a replacement deletes the previous file. Validation failures (missing
part, wrong mimetype, too large) → **422**.

## Operator / internal endpoints

Not part of the Android client contract; documented so the security boundary is legible.

```
GET    /api/v1/health                                         -> liveness + DB probe
POST   /api/v1/errors  { device_id, error_code,
                         message?, app_version? }             -> 201; UNAUTHENTICATED,
                                                                 throttled per device+IP (429),
                                                                 pruned by retention
```

`GET /health` returns `{ name, api, status, database }`. It runs a `SELECT 1` probe: a
reachable DB → **200** `status: ok, database: ok`; an unreachable DB → **503**
`status: error, database: unavailable` (the raw DB error is logged, never returned), so an
orchestrator sees a real failure instead of a misleading 200.

**Admin API** — guarded by a static bearer token (`INVENTORY_ADMIN_TOKEN` via the
`inventory.admin` middleware, *not* Sanctum user auth); disabled when the token is unset:

```
GET    /api/v1/admin/users            GET /api/v1/admin/users/search   GET/DELETE /api/v1/admin/users/{id}
GET    /api/v1/admin/households                                        GET/DELETE /api/v1/admin/households/{id}
```

An **MCP** server (`routes/mcp.php`) is also mounted when `laravel/mcp` is installed on the
host — operator tooling, outside this client contract.

## Rate limiting

Brute-forceable + floodable surfaces are throttled (per-minute, env-tunable; a **429** with
the standard Laravel throttle headers is returned when exceeded):

- **Auth** (`register`/`login`/`google`/`forgot-password`) — per submitted email + IP, under
  a looser per-IP cap.
- **`households/join`** — per authenticated user (join-code guessing).
- **`/errors`** — per `device_id` + IP.

## Conventions

- Form Requests validate input at the boundary; API Resources shape responses.
- Route-model binding scoped to the household; a resource that doesn't belong to the
  path household returns 404 (not 403 — don't leak existence).
- Concurrency is **last-write-wins** — no version / If-Match / optimistic-lock headers.
- Errors: standard Laravel JSON error envelope; 401 unauth, 403 non-member,
  404 not-found/out-of-tenant, 422 validation, **429 rate-limited** (see *Rate limiting*).
