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
PATCH  /api/v1/households/{household}      { name?, color?,   -> partial update: rename + theme
                                             icon? }             (any member; null clears theme)
GET    /api/v1/households/{household}/invite                  -> { code, link }
                                                                 (client renders QR from link)
POST   /api/v1/households/join             { code }           -> join by code
DELETE /api/v1/households/{household}/leave                   -> leave self (409 for the sole
                                                                 Owner: transfer first)
DELETE /api/v1/households/{household}      { name }           -> Owner-only hard delete; body must
                                                                 carry the EXACT current name as a
                                                                 typed confirmation (422 mismatch,
                                                                 403 non-owner)
GET    /api/v1/households/{household}/export                  -> versioned JSON export of the tree
GET    /api/v1/households/{household}/deleted                 -> restorable deletion batches
                                                                 (any member; read-only twin of the
                                                                 web "Recently deleted" section)
GET    /api/v1/households/{household}/search?q=               -> matching products (see Search result)
```

### Roles & member management (shipped 2026-07-17)

Every membership row carries a `role`: `owner` | `admin` | `member`. A household
always has **exactly one Owner**; the only way that changes is transfer-ownership,
which atomically demotes the caller to Admin (conditional demote-first — a lost
concurrent race 409s instead of minting two owners).

```
GET    /api/v1/households/{household}/members                    -> roster (HouseholdMemberResource)
PATCH  /api/v1/households/{household}/members/{user}  { role }   -> promote/demote admin<->member
                                                                    (manageMembers; the Owner's own
                                                                    row 403s - transfer instead)
DELETE /api/v1/households/{household}/members/{user}             -> remove member (manageMembers;
                                                                    the Owner 403s)
POST   /api/v1/households/{household}/transfer-ownership { user_id } -> Owner-only; 422 self,
                                                                    409 lost race
```

Capability gates (HouseholdPolicy): `restructure` + `manageMembers` = Owner/Admin;
`transferOwnership` + `delete` = Owner. Clients gate UI off the server-computed
flags below — never re-derive from `role` client-side.

**Household resource** (`HouseholdResource`) — returned by list/create/join:

```
{ id, name,
  join_code,                   # visible to members (all members are equal and may
                               # invite); this resource is only ever returned to members.
                               # Client field is required — a rename breaks deserialization.
  color, icon,                 # nullable theme KEYS (Phase 2) — color: sky|teal|indigo|pink|
                               # amber|green|violet|orange; icon: home|kitchen|house|apartment|
                               # cottage|warehouse|storefront|box. null = client derives a
                               # stable default from the household id.
  role,                        # the CALLER's own role: owner|admin|member
  can_restructure,             # server-computed capability flags the client gates UI on
  can_manage_members }
```

The invite endpoint returns `{ code, link }` where `link` is
`https://{domain}/join/{code}` — a real page (Frost-styled) that shows the code and
points at the app, not just an app-only deep link.

## Live updates (broadcasting)

```
POST /api/v1/broadcasting/auth   { channel_name, socket_id }   -> Pusher-protocol channel auth
                                                                  (Sanctum bearer, member-gated)
```

Every mutation of a household's tree (household rename/theme, location/shelf/product
create/update/delete, stock changes) broadcasts **`household.changed`** on the private
channel **`inventory.household.{id}`** with payload `{ household_id }`. The ping carries
NO state — clients re-fetch on receipt (server-authoritative), identical to a manual
pull-to-refresh. Served by Laravel Reverb (Pusher protocol) on the host; with no
broadcaster configured the events are silent no-ops.

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
       /locations[/{location}]                       CRUD*  (type: freezer|fridge|pantry|other)
       /locations/{location}/shelves[/{shelf}]       CRUD*
       /shelves/{shelf}/products[/{product}]         CRUD

POST   /products/{product}/add     { amount }        -> increment quantity (atomic)
POST   /products/{product}/remove  { amount }        -> decrement (atomic, floor 0)
POST   /products/{product}/move    { shelf_id }      -> relocate within the household
POST   /products/{product}/image   (multipart)       -> upload photo, sets image_url

PATCH  /locations/reorder                          { ids: [int] }
PATCH  /locations/{location}/shelves/reorder        { ids: [int] }
```

\* Locations and shelves are soft-deleted (see *Deleting a location or shelf* below) — their
`DELETE` is not a bare CRUD delete, and shelves also accept a reparenting `PATCH`.

`reorder` rewrites every sibling's `position` from the client's ordered id list, in one
all-or-nothing transaction. `ids` must be **complete**: exactly the set of live ids of
that parent — every household location, resp. every **non-system** shelf of that
location (the **Unsorted** shelf, `is_system: true`, is excluded: it's never draggable
and always sorts last, so the client never sends its id and it doesn't count toward
completeness) — not a subset, not a superset, no foreign or soft-deleted ids. Any gap
is a **422** with no row touched; a half-applied reorder is worse than a rejected one.
Broadcasts `household.changed` on success (a query-builder `update()` fires no Eloquent
events, so this is an explicit dispatch, not the observer).

`add`/`remove` apply an **atomic** quantity delta (1 ≤ `amount` ≤ 1,000,000); `remove`
floors at 0. `amount`/`quantity` are capped at 1,000,000 — an over-cap value is a **422**,
not a 500 (keeps the `unsignedInteger` column from overflowing).

### Deleting a location or shelf

Locations and shelves carry `deleted_at` + `deletion_batch_id` (soft delete, not a hard
`DELETE`). Deleting one that still holds something REQUIRES an explicit strategy — the
server never guesses, because guessing wrong destroys data:

```
DELETE /locations/{location}        { deletion_batch_id?,               -> 200; soft-deletes
                                       strategy?, target_location_id? }    the location (+ subtree)
DELETE /locations/{location}/shelves/{shelf}
                                     { deletion_batch_id?,               -> 200; soft-deletes
                                       strategy?, target_shelf_id? }       the shelf (+ its products)
```

- `deletion_batch_id` (**optional**, uuid) — client-minted when present, since only the
  client knows whether several deletes in a row are one user gesture (e.g. deleting three
  shelves in one screen action); a client-supplied id is always used verbatim and lets
  those requests share one batch, so Undo restores the whole gesture as a unit, not one
  item of several. **Back-compat guarantee:** omitted or explicit `null` is accepted — the
  **server mints its own batch-of-one uuid** in that case, so the row still lands
  genuinely restorable through `POST .../restore/{batch}` rather than stuck with a `NULL`
  `deletion_batch_id` (permanently unreachable through the batch-keyed restore surface).
  This exists because a shipped Android build (v0.1.8) sends a bodyless `DELETE` with no
  batch id at all, and the API-versioning rule at the top of this doc means that build
  must keep working unmodified. Present but non-uuid (e.g. an empty string or a garbage
  value) -> 422; the server never guesses at a malformed id, only fills in a genuinely
  absent one. The response body always echoes the `deletion_batch_id` that was actually
  stamped — client-supplied or server-minted — so the caller can use it to restore.
- `strategy` is **required** only when the container is non-empty — a location holding
  any **non-system** shelf (whatever it holds — its own fate as a shelf still needs
  deciding), or holding a system **Unsorted** shelf that itself holds products; a shelf
  holding products — and **not** required for an empty one. An empty, auto-created
  Unsorted shelf on its own does **not** count as "non-empty": it is a disposable
  implementation detail the user never created and never sees (see
  `StorageLocation::shelvesWithContents()`), so its mere existence must not force a
  strategy prompt. This is exactly `LocationResource.shelf_count` below — the client MUST
  ask for a strategy iff `shelf_count > 0`, since that field and this rule are read from
  the same server-side query and are guaranteed to agree. A location deleted this way with
  no strategy leaves any such empty Unsorted shelf live but orphaned under the (now
  soft-deleted) location — harmless, invisible via the household-scoped listing routes,
  and reclaimed for real by the retention purge's cascade if the location is never
  restored:
  - **Location** `strategy`: `move_contents` (reparent the location's shelves into
    `target_location_id`, required with this strategy — products hang off the shelf and
    ride along unmoved) | `delete_contents` (soft-delete the shelves and their products
    alongside the location, all in the same batch). There is no `unsort` option at this
    level: "unsorted" means off-shelf but still *in* the location, and the location is
    what's being deleted.
  - **Shelf** `strategy`: `move_products` (reassign to `target_shelf_id`, required with
    this strategy) | `unsort_products` (reassign to the location's **Unsorted** shelf — a
    lazily-created, per-location system shelf, `is_system: true`, that holds products
    whose shelf was deleted but which the user chose to keep; the client localises its
    label off `is_system`, not off `name`) | `delete_products` (soft-delete alongside the
    shelf, same batch).
- `target_location_id` / `target_shelf_id` must be a live resource of the **same
  household**, and not the container being deleted itself — either violation is a 422 on
  that field, with nothing touched.
- A `move_contents` whose location owns an Unsorted shelf never reparents that shelf
  as-is (it would produce two live Unsorted shelves in the target); its products, if any,
  are merged into the target's own Unsorted shelf instead, and the now-empty source one is
  soft-deleted alongside the rest of the batch.

`PATCH /locations/{location}/shelves/{shelf}` additionally accepts a writable
`location_id`, reparenting the shelf to another location — same household only (a
foreign-household target is 422; a `Rule::exists` in the request can't see the household,
so this is enforced in the controller). Rejected with 422 on a **system** shelf
(`is_system: true`): the Unsorted shelf can't be renamed *or* moved, for the same reason
`move_contents` never reparents it — moving it as-is into a location that already has its
own would leave two live Unsorted shelves there.

### Restoring a deletion batch

```
POST /households/{household}/restore/{batch}        -> 200 { message, restored: int }
```

The deletes above stamp every row they touch with `deletion_batch_id` — the whole gesture
is undoable as one unit through this single endpoint. Every location/shelf/product row
stamped with `deletion_batch_id = {batch}` is restored (`deleted_at` and
`deletion_batch_id` both cleared) in a single all-or-nothing transaction. `restored` is
the total row count restored across all three tables. `batch` is keyed at the
**household** level, not by resource id — a soft-deleted resource is filtered out of
scoped route-model binding, so a restore route keyed by e.g. `{shelf}` could never be
reached once the row is trashed.

`batch` is client-minted (the client is the only party that knows whether several deletes
in a row are one user gesture) and therefore **guessable**. Restoring is scoped to rows
reachable from the caller's own household: locations by `household_id`; shelves/products
by walking down from that household's own location/shelf ids, since neither table carries
a `household_id` column. A guessed batch id belonging to another household's rows never
restores anything.

**409, never 404.** All of the following produce the same `409 { message }`, with nothing
written:

- an unknown batch id;
- a batch belonging to a different household;
- a batch already restored (the first restore clears `deletion_batch_id`, so a replay finds
  nothing to match);
- a batch where any row's **parent is still soft-deleted under a different, later batch** —
  e.g. a shelf deleted alone (batch A), then its location deleted with `delete_contents`
  (batch B, which skips the already-trashed shelf and only stamps the location): restoring
  A alone would resurrect the shelf under a location that is still dead. The server never
  guesses here; the parent must be restored first (restore its own batch), then the child.

This is deliberate: 404 would let a caller distinguish "wrong household" from "already
restored" or "nothing to restore," which the server never reveals.

**Location resource** (`LocationResource`):

```
{ id, name, type,               # type: freezer | fridge | pantry | other
  position,                    # server-assigned manual order; index is
                                # orderBy('position').orderBy('name'), so an
                                # undragged location falls back to name order
  shelf_count,                  # live shelf count, EXCLUDING an empty system
                                # Unsorted shelf (see *Deleting a location or
                                # shelf* above) — the client MUST decide
                                # "ask for a delete strategy?" from
                                # shelf_count > 0 alone; that is exactly the
                                # server's own rule, so a strategy-less delete
                                # sent when shelf_count == 0 always succeeds
  product_count }               # live product count across every shelf in the
                                # location (including the Unsorted one, if any).
                                # index() eager-loads both via withCount() to
                                # avoid an N+1 across many locations — the
                                # delete-confirmation dialog needs them to show
                                # "N shelves · N products" before the user picks
```

**Shelf resource** (`ShelfResource`):

```
{ id, name,
  position,                    # server-assigned order (max+1 on create); index is
                               # orderBy('is_system').orderBy('position'), so the
                               # client's tab/pager order is stable and Unsorted
                               # always sorts last regardless of its position value
  location_id,                 # parent location; client field is required
  is_system,                   # true only for the lazily-created "Unsorted" shelf (see
                               # *Deleting a location or shelf*): unrenameable, unmovable,
                               # excluded from reorder — the client localises its label
                               # off this flag, not off `name`
  product_count }              # live product count; index() eager-loads it (withCount)
                               # to avoid an N+1 across many shelves — the delete-strategy
                               # dialog needs it to show "N products" before the user picks
```

### Product shape

Response (`ProductResource`):

```
{ id, shelf_id, name, quantity,
  low_stock_threshold,         # nullable int >= 1; quantity <= threshold = "running low"; null = off
  description | null,          # free-form notes
  code | null,                 # free-form product code / barcode
  is_mandatory,                # bool; a mandatory item at quantity 0 = "missing"
  is_starred,                  # bool, default false; user-toggled favorite/pin — no
                               # server-side sort/filter semantics, storage + passthrough
                               # only (added 2026-07-13)
  image_url | null }           # absolute URL of the product photo; null until one is uploaded
```

Create body (`POST …/products`): `name` (required, ≤50) + optional `quantity` (≥0),
`description`, `code` (≤100), `is_mandatory`, `is_starred`. Update body (`PUT/PATCH
…/products/{id}`): the same fields, all optional. `image_url` is **not** settable via
create/update — it is managed solely by the image-upload endpoint below.

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

## Web route mirroring (parity program, 2026-07-18)

The session-guarded web surface mirrors the API's mutating endpoints as thin
wrappers over the same support classes and policies — `Reorderer` (reorder),
`Restorer` (restore-by-batch), `HierarchyDeleter` + the API's own
`DeleteLocationRequest`/`DeleteShelfRequest` (delete strategies), and
`HouseholdPolicy` throughout. When changing an API endpoint's semantics,
check `routes/web.php` for its web twin — they must move together.
