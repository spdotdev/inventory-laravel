# MCP Admin-List Pagination Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let `list_users`, `list_households`, and `search_users` page through more than 50 rows, via `page`/`per_page` query params on both the HTTP admin endpoints and their MCP tool wrappers.

**Architecture:** `AdminController`'s three methods swap their hard `->limit(50)->get()` tail for Eloquent's `paginate()`, returning a trimmed `{"data": [...], "meta": {page, per_page, total, last_page}}` shape; the three matching MCP tools gain optional `page`/`per_page` zod params forwarded as query-string parameters; the cross-repo tool manifest is updated in the same task as the tool-schema change.

**Tech Stack:** Laravel 13 (PHP), PHPUnit; Node/TypeScript MCP SDK, zod.

## Global Constraints

- Backward compatible: no `page`/`per_page` passed → identical result to today (first 50 rows, same order, same `data` shape) — only an added `meta` key is new.
- `per_page` clamped to `[1, 100]` server-side regardless of what's requested; `page` floored at 1.
- Response shape: `{"data": [...same per-item payload as today...], "meta": {"page": int, "per_page": int, "total": int, "last_page": int}}` — NOT Laravel's default paginator JSON (no `*_page_url`/`links` fields).
- MCP: query-string construction mirrors `search_users`'s existing `?q=${encodeURIComponent(q)}` pattern — no new HTTP client mechanism.
- Manifest: `docs/specs/mcp-tools.json`'s `list_users`/`list_households`/`search_users` entries and `inventory-mcp`'s `test/server.test.mjs` must be updated together with the tool-schema change, in the SAME task, to avoid the cross-repo CI-red cycle hit twice already on 2026-07-24 building the app-update-notifications feature.
- The existing `test_listing_users_is_capped`/`test_listing_households_is_capped` tests in `tests/Feature/AdminApiTest.php` assert exactly 50 rows are returned with NO page/per_page params sent — since the new default is also 50, these two tests must keep passing UNCHANGED (do not modify them; if they fail, something broke backward compatibility).
- `inventory-mcp`'s `"exposes exactly the ten admin tools"` test (asserts tool NAMES only, not param shapes) does not need to change — adding params to existing tools' `inputSchema` doesn't touch that set.

---

## Task 1: Backend pagination on `AdminController`

**Files:**
- Modify: `src/Http/Controllers/Api/AdminController.php`
- Modify: `tests/Feature/AdminApiTest.php`

**Interfaces:**
- Produces: `GET /api/v1/admin/users?page=N&per_page=N`, `GET /api/v1/admin/households?page=N&per_page=N`, `GET /api/v1/admin/users/search?q=...&page=N&per_page=N` — each returning `{"data": [...], "meta": {"page": int, "per_page": int, "total": int, "last_page": int}}`.

- [ ] **Step 1: Update `listUsers`** — add a `Request $request` parameter (it currently takes none) and swap the query tail:

```php
public function listUsers(Request $request): JsonResponse
{
    $page = max(1, (int) $request->input('page', 1));
    $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

    $paginator = User::query()
        ->withCount('households')
        ->orderBy('created_at', 'desc')
        ->paginate($perPage, ['*'], 'page', $page);

    $paginator->getCollection()->transform(fn (User $u) => $this->userPayload($u));

    return response()->json([
        'data' => $paginator->items(),
        'meta' => [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ],
    ]);
}
```

- [ ] **Step 2: Update `listHouseholds`** the same way:

```php
public function listHouseholds(Request $request): JsonResponse
{
    $page = max(1, (int) $request->input('page', 1));
    $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

    $paginator = Household::query()
        ->withCount(['users', 'locations', 'shelves'])
        ->orderBy('created_at', 'desc')
        ->paginate($perPage, ['*'], 'page', $page);

    $paginator->getCollection()->transform(fn (Household $h) => $this->householdPayload($h));

    return response()->json([
        'data' => $paginator->items(),
        'meta' => [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ],
    ]);
}
```

- [ ] **Step 3: Update `searchUsers`** — keep the existing `q` filtering, swap only the tail:

```php
public function searchUsers(Request $request): JsonResponse
{
    $query = (string) $request->input('q', '');
    $page = max(1, (int) $request->input('page', 1));
    $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

    $escaped = Like::escape($query);

    $paginator = User::query()
        ->withCount('households')
        ->where(function ($q) use ($escaped) {
            $q->whereRaw("name LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                ->orWhereRaw("email LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
        })
        ->orderBy('created_at', 'desc')
        ->paginate($perPage, ['*'], 'page', $page);

    $paginator->getCollection()->transform(fn (User $u) => $this->userPayload($u));

    return response()->json([
        'data' => $paginator->items(),
        'meta' => [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ],
    ]);
}
```

- [ ] **Step 4: Run the existing tests to confirm no regression** — `test_listing_users_is_capped` and `test_listing_households_is_capped` must pass UNCHANGED (they send no page/per_page params, so the new default-50 behavior must produce an identical result):

Run: `vendor/bin/phpunit --filter AdminApiTest`
Expected: PASS, all existing tests including the two capped-listing tests, with zero modifications to those two test methods.

- [ ] **Step 5: Add new tests to `tests/Feature/AdminApiTest.php`** covering the new pagination behavior:

```php
public function test_listing_users_respects_per_page(): void
{
    for ($i = 0; $i < 15; $i++) {
        User::create(['name' => "U{$i}", 'email' => "u{$i}@example.test", 'password' => 'secret-password']);
    }

    $response = $this->getJson("{$this->base}/users?per_page=10", $this->auth())->assertOk();

    $this->assertCount(10, $response->json('data'));
    $this->assertSame(10, $response->json('meta.per_page'));
    $this->assertSame(15, $response->json('meta.total'));
    $this->assertSame(2, $response->json('meta.last_page'));
}

public function test_listing_users_page_two_returns_the_next_slice(): void
{
    for ($i = 0; $i < 15; $i++) {
        User::create(['name' => "U{$i}", 'email' => "u{$i}@example.test", 'password' => 'secret-password']);
    }

    $page1 = $this->getJson("{$this->base}/users?per_page=10&page=1", $this->auth())->assertOk()->json('data');
    $page2 = $this->getJson("{$this->base}/users?per_page=10&page=2", $this->auth())->assertOk()->json('data');

    $page1Ids = array_column($page1, 'id');
    $page2Ids = array_column($page2, 'id');

    $this->assertCount(10, $page1);
    $this->assertCount(5, $page2);
    $this->assertEmpty(array_intersect($page1Ids, $page2Ids));
}

public function test_listing_users_clamps_per_page_to_one_hundred(): void
{
    User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);

    $response = $this->getJson("{$this->base}/users?per_page=500", $this->auth())->assertOk();

    $this->assertSame(100, $response->json('meta.per_page'));
}

public function test_listing_households_respects_per_page(): void
{
    for ($i = 0; $i < 15; $i++) {
        Household::create(['name' => "H{$i}", 'join_code' => sprintf('AAAA-%04d', $i)]);
    }

    $response = $this->getJson("{$this->base}/households?per_page=10", $this->auth())->assertOk();

    $this->assertCount(10, $response->json('data'));
    $this->assertSame(15, $response->json('meta.total'));
}

public function test_search_users_respects_pagination(): void
{
    for ($i = 0; $i < 15; $i++) {
        User::create(['name' => "Match{$i}", 'email' => "match{$i}@example.test", 'password' => 'secret-password']);
    }
    User::create(['name' => 'NoMatch', 'email' => 'nomatch@example.test', 'password' => 'secret-password']);

    $response = $this->getJson("{$this->base}/users/search?q=Match&per_page=10", $this->auth())->assertOk();

    $this->assertCount(10, $response->json('data'));
    $this->assertSame(15, $response->json('meta.total'));
}
```

- [ ] **Step 6: Run the new tests**

Run: `vendor/bin/phpunit --filter AdminApiTest`
Expected: PASS (all tests, old and new — should be 12 total in this file after adding 5 new ones to the existing 7... count is illustrative, just confirm all green).

- [ ] **Step 7: Run the full suite, Pint, and Larastan**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=512M`
Expected: all PASS, no regressions.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/Api/AdminController.php tests/Feature/AdminApiTest.php
git commit -m "feat: add page/per_page pagination to admin list/search endpoints"
```

---

## Task 2: MCP tool params + cross-repo manifest

**Files:**
- Modify: `docs/specs/mcp-tools.json` (in `inventory-laravel`)
- Modify: `/home/dev/inventory/inventory-mcp/src/server.ts`
- Modify: `/home/dev/inventory/inventory-mcp/test/server.test.mjs`

**Interfaces:**
- Consumes: Task 1's `page`/`per_page`-aware endpoints.
- Produces: `list_users`, `list_households`, `search_users` MCP tools each accepting optional `page: number`/`per_page: number` args, forwarded as query-string params.

This task touches two repos (`inventory-laravel`'s manifest file and `inventory-mcp`'s tool code/tests) — do both halves before committing either, so the manifest and the tool schema never drift out of sync even for one commit.

- [ ] **Step 1: Update the manifest** — in `/home/dev/inventory/inventory-laravel/docs/specs/mcp-tools.json`, change the `list_users`, `search_users`, and `list_households` entries' `params` arrays:

```json
{
    "key": "list_users",
    "scope": "admin",
    "destructive": false,
    "params": [
        { "name": "page", "type": "integer", "required": false },
        { "name": "per_page", "type": "integer", "required": false }
    ]
},
```

```json
{
    "key": "search_users",
    "scope": "admin",
    "destructive": false,
    "params": [
        { "name": "q", "type": "string", "required": true },
        { "name": "page", "type": "integer", "required": false },
        { "name": "per_page", "type": "integer", "required": false }
    ]
},
```

```json
{
    "key": "list_households",
    "scope": "admin",
    "destructive": false,
    "params": [
        { "name": "page", "type": "integer", "required": false },
        { "name": "per_page", "type": "integer", "required": false }
    ]
},
```

- [ ] **Step 2: Run `McpToolManifestTest`** (this repo, `inventory-laravel`) to confirm the manifest change alone doesn't break anything yet — it WILL fail at this point, and that's expected, since the embedded server's tools don't have these params yet:

Run: `vendor/bin/phpunit --filter McpToolManifestTest`
Expected: FAIL — `list_users`/`search_users`/`list_households` params differ from the manifest. This confirms the manifest edit took effect; Step 3 below is what makes it pass again.

- [ ] **Step 3: Update the embedded server's matching tools** — the manifest comment states admin-scope tools are "mirrored 1:1 by the EMBEDDED HTTP server in this repo, `src/Mcp/`". These embedded tools call the Eloquent models directly (not the HTTP endpoint), so mirror Task 1's `AdminController` query/response logic inside each tool's own `handle()`.

Rewrite `src/Mcp/Tools/ListUsersTool.php` in full:

```php
<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\User;

#[Description('List all registered inventory users with their household counts, ordered newest first.')]
class ListUsersTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number, 1-indexed (default 1).'),
            'per_page' => $schema->integer()->description('Rows per page, max 100 (default 50).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 50)));

        $paginator = User::query()
            ->withCount('households')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return Response::json([
            'data' => $paginator->getCollection()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'google_id' => $u->google_id,
                'created_at' => $u->created_at,
                'households_count' => $u->households_count,
            ])->toArray(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
```

Rewrite `src/Mcp/Tools/SearchUsersTool.php` in full:

```php
<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Support\Like;

#[Description('Search inventory users by name or email address.')]
class SearchUsersTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Name or email search query.')->required(),
            'page' => $schema->integer()->description('Page number, 1-indexed (default 1).'),
            'per_page' => $schema->integer()->description('Rows per page, max 100 (default 50).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $q = (string) $request->get('q');
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 50)));

        $escaped = Like::escape($q);

        $paginator = User::query()
            ->withCount('households')
            ->where(function ($query) use ($escaped) {
                $query->whereRaw("name LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                    ->orWhereRaw("email LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return Response::json([
            'data' => $paginator->getCollection()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'google_id' => $u->google_id,
                'created_at' => $u->created_at,
            ])->toArray(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
```

Rewrite `src/Mcp/Tools/ListHouseholdsTool.php` in full:

```php
<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\Household;

#[Description('List all households with member, location, and shelf counts, ordered newest first.')]
class ListHouseholdsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number, 1-indexed (default 1).'),
            'per_page' => $schema->integer()->description('Rows per page, max 100 (default 50).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 50)));

        $paginator = Household::query()
            ->withCount(['users', 'locations', 'shelves'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return Response::json([
            'data' => $paginator->getCollection()->map(fn (Household $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'join_code' => $h->join_code,
                'created_at' => $h->created_at,
                'members' => $h->users_count,
                'locations' => $h->locations_count,
                'shelves' => $h->shelves_count,
            ])->toArray(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
```

Note: this changes `ListUsersTool`'s and `ListHouseholdsTool`'s response shape (adds a `data`/`meta` wrapper where before they returned a bare array) — check `tests/Feature/McpToolsTest.php`'s existing `test_list_households_reports_real_counts` test (uses `InventoryAdminServer::tool(ListHouseholdsTool::class)->assertSee('"members":1')` etc.) and update its assertions if the bare-array-vs-wrapped-object change breaks its `assertSee` string matches — the counts/keys themselves (`members`, `locations`, `shelves`) are unchanged, only the top-level wrapper is new, so `assertSee` (a substring match) should still find `"members":1` regardless of the wrapping, but verify this by actually running that test rather than assuming.

- [ ] **Step 4: Run `McpToolManifestTest`** again

Run: `vendor/bin/phpunit --filter McpToolManifestTest`
Expected: PASS.

- [ ] **Step 5: Run the full Laravel test suite, Pint, and Larastan**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=512M`
Expected: all PASS, no regressions.

- [ ] **Step 6: Commit the Laravel-side manifest + embedded-tool changes**

```bash
git add docs/specs/mcp-tools.json src/Mcp/Tools/ListUsersTool.php src/Mcp/Tools/SearchUsersTool.php src/Mcp/Tools/ListHouseholdsTool.php
git commit -m "feat: mirror page/per_page pagination in the embedded MCP tools"
```

- [ ] **Step 7: Push this commit immediately** (before touching `inventory-mcp`) — `inventory-mcp`'s CI conformance check fetches the manifest live from `inventory-laravel`'s `main` branch, so pushing now means Step 12's conformance run below tests against the real, already-live manifest rather than a stale one:

```bash
git push origin main
```

- [ ] **Step 8: Update the standalone server's tools** — in `/home/dev/inventory/inventory-mcp/src/server.ts`:

```typescript
  server.registerTool(
    "list_users",
    {
      description: "List all registered inventory users with household counts.",
      inputSchema: {
        page: z.number().int().positive().optional().describe("Page number, 1-indexed (default 1)"),
        per_page: z.number().int().positive().max(100).optional().describe("Rows per page, max 100 (default 50)"),
      },
      annotations: { readOnlyHint: true },
    },
    async ({ page, per_page }) => {
      const params = new URLSearchParams();
      if (page !== undefined) params.set("page", String(page));
      if (per_page !== undefined) params.set("per_page", String(per_page));
      const qs = params.toString();
      return asText(await adminFetch(`/users${qs ? `?${qs}` : ""}`));
    },
  );

  server.registerTool(
    "search_users",
    {
      description: "Search inventory users by name or email.",
      inputSchema: {
        q: z.string().describe("Name or email search query"),
        page: z.number().int().positive().optional().describe("Page number, 1-indexed (default 1)"),
        per_page: z.number().int().positive().max(100).optional().describe("Rows per page, max 100 (default 50)"),
      },
      annotations: { readOnlyHint: true },
    },
    async ({ q, page, per_page }) => {
      const params = new URLSearchParams({ q });
      if (page !== undefined) params.set("page", String(page));
      if (per_page !== undefined) params.set("per_page", String(per_page));
      return asText(await adminFetch(`/users/search?${params.toString()}`));
    },
  );
```

```typescript
  server.registerTool(
    "list_households",
    {
      description: "List all households with member/location/product counts.",
      inputSchema: {
        page: z.number().int().positive().optional().describe("Page number, 1-indexed (default 1)"),
        per_page: z.number().int().positive().max(100).optional().describe("Rows per page, max 100 (default 50)"),
      },
      annotations: { readOnlyHint: true },
    },
    async ({ page, per_page }) => {
      const params = new URLSearchParams();
      if (page !== undefined) params.set("page", String(page));
      if (per_page !== undefined) params.set("per_page", String(per_page));
      const qs = params.toString();
      return asText(await adminFetch(`/households${qs ? `?${qs}` : ""}`));
    },
  );
```

- [ ] **Step 9: Add pagination tests to `test/server.test.mjs`**, following the file's existing `stubFetch`/`connectedClient` pattern:

```javascript
test("list_users forwards page and per_page as query params", async () => {
  const fetchStub = stubFetch({ body: { data: [], meta: { page: 2, per_page: 10, total: 15, last_page: 2 } } });
  const client = await connectedClient(fetchStub);

  await client.callTool({ name: "list_users", arguments: { page: 2, per_page: 10 } });

  assert.equal(fetchStub.calls[0].url, `${BASE_URL}/admin/users?page=2&per_page=10`);
});

test("list_users omits query string entirely when no pagination args are given", async () => {
  const fetchStub = stubFetch({ body: { data: [] } });
  const client = await connectedClient(fetchStub);

  await client.callTool({ name: "list_users", arguments: {} });

  assert.equal(fetchStub.calls[0].url, `${BASE_URL}/admin/users`);
});

test("search_users forwards q, page, and per_page together", async () => {
  const fetchStub = stubFetch({ body: { data: [] } });
  const client = await connectedClient(fetchStub);

  await client.callTool({ name: "search_users", arguments: { q: "stan", page: 1, per_page: 25 } });

  assert.equal(fetchStub.calls[0].url, `${BASE_URL}/admin/users/search?q=stan&page=1&per_page=25`);
});

test("list_households forwards page and per_page as query params", async () => {
  const fetchStub = stubFetch({ body: { data: [] } });
  const client = await connectedClient(fetchStub);

  await client.callTool({ name: "list_households", arguments: { page: 3 } });

  assert.equal(fetchStub.calls[0].url, `${BASE_URL}/admin/households?page=3`);
});

test("list_users rejects a per_page over 100 before any network call", async () => {
  const fetchStub = stubFetch();
  const client = await connectedClient(fetchStub);

  const result = await client.callTool({ name: "list_users", arguments: { per_page: 500 } });

  assert.equal(result.isError, true);
  assert.equal(fetchStub.calls.length, 0);
});
```

- [ ] **Step 10: Build and run the full test suite**

Run: `npm run build && npm test`
Expected: PASS, including the `"exposes exactly the ten admin tools"` count assertion (unaffected — tool names didn't change) and all new pagination tests.

- [ ] **Step 11: Commit**

```bash
git add src/server.ts test/server.test.mjs
git commit -m "feat: add page/per_page pagination args to list/search MCP tools"
git push origin main
```

- [ ] **Step 12: Run the conformance check against the live (already-pushed) manifest** to confirm both repos agree:

Run: `node scripts/conformance.mjs`
Expected: `Conformant: N tools match https://raw.githubusercontent.com/spdotdev/inventory-laravel/main/docs/specs/mcp-tools.json` — no `extra`/`missing`/param-mismatch errors for `list_users`, `search_users`, or `list_households`.

---

## Manual verification (optional, not required for this small a change)

Since this is a query-param addition to an already-live admin endpoint with no client UI (Claude is the only caller via MCP), no device/browser verification is needed. If desired: call `create_app_release`... no — call `list_users` via the `inventory-admin` MCP server registered in this project's `.claude/settings.json` with `{"per_page": 5}` and confirm the response's `meta` block looks right against the live production data.
