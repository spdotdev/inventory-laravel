# MCP admin-list pagination — design

Date: 2026-07-24
Repos touched: `inventory-laravel` (backend), `inventory-mcp` (tool params + manifest)

## Problem

`AdminController::listUsers`, `listHouseholds`, and `searchUsers` are all hard-capped
at `->limit(50)` with no way to see anything past the first 50 rows. This blocks
scaling the admin/MCP surface past 50 users or households — a real limitation
already flagged in `inventory-mcp/BACKLOG.md`, not a hypothetical one.

## Goals

- Let `list_users`, `list_households`, and `search_users` (both the HTTP admin
  endpoints and their MCP tool wrappers) page through more than 50 rows.
- Keep the change additive/backward-compatible: a caller that passes neither
  `page` nor `per_page` gets exactly today's behavior (first 50 rows, same order).
- Return enough metadata (total count, current page, last page) that an LLM caller
  can reason about whether to fetch another page, without dumping Laravel's full
  default paginator JSON (which is mostly URL fields irrelevant to a non-browser
  caller).

## Non-goals

- No change to `get_user`/`get_household`/any single-resource endpoint.
- No cursor-based pagination — page+per_page is sufficient at this endpoint's
  scale and read pattern (see the brainstorm discussion: low write-volume, LLM
  callers reason more naturally about page numbers than opaque cursors).
- No pagination on any household-scoped (non-admin) listing endpoint — this is
  scoped to the three admin-surface endpoints named above only.

## Architecture

`AdminController`'s three methods each accept two new optional query params:

- `page` (int, default 1)
- `per_page` (int, default 50, clamped to `[1, 100]`)

Each method uses Eloquent's `paginate($perPage, ['*'], 'page', $page)` for the
actual query/count, then discards the paginator's default JSON shape in favor of:

```json
{
  "data": [ /* same per-item payload shape as today, unchanged */ ],
  "meta": {
    "page": 1,
    "per_page": 50,
    "total": 132,
    "last_page": 3
  }
}
```

On the `inventory-mcp` side, `list_users`, `list_households`, and `search_users`
each gain optional `page`/`per_page` zod params, forwarded as query-string
parameters to the same admin endpoints (mirroring `search_users`'s existing
`?q=${encodeURIComponent(q)}` query-string pattern — no new HTTP client
mechanism needed).

## Backend detail

For each of the three methods (shown for `listUsers`, the other two follow
identically):

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

`searchUsers` keeps its existing `LIKE`/`whereRaw` filtering, just swaps the bare
`->limit(50)->get()` tail for the same `paginate()` + trimmed-meta pattern.
`listHouseholds` is the direct `Household` equivalent of `listUsers` above.

**Backward compatibility**: `page` defaults to 1, `per_page` defaults to 50 — a
caller passing neither gets the identical first-50-rows result as today, just
wrapped with an added `meta` key (the previously-bare `data` key is untouched in
shape). No existing caller inspects the response shape strictly enough to break
on an added key (verified: neither this repo's own admin API tests nor
`inventory-mcp`'s tests assert an exact/closed JSON shape for these three
endpoints — only specific fields via `assertJsonPath`/property access).

## MCP detail

```ts
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
```

`list_households` mirrors this exactly. `search_users` keeps its existing `q`
param and adds the same `page`/`per_page` pair, combining all three into one
query string.

## Manifest update

`docs/specs/mcp-tools.json`'s `list_users`, `list_households`, and
`search_users` entries each gain two new optional params (`page: integer,
required: false`, `per_page: integer, required: false`) in their `params`
arrays — this is the exact cross-repo guard (`McpToolManifestTest` +
`inventory-mcp`'s conformance script) hit twice already today while building the
app-update-notifications feature; getting the manifest updated in the same
commit as the tool-schema change avoids a repeat of that CI-red cycle.

## Testing

- Backend: a feature test per endpoint asserting (a) no page/per_page params
  behaves identically to today (50 rows, no regression), (b) `per_page=10`
  actually returns 10 rows when more exist, (c) `page=2` returns the next slice
  (no overlap with page 1), (d) `per_page` is clamped to 100 when a caller
  requests more, (e) `meta.total`/`meta.last_page` reflect the real row count.
- MCP: extend the existing tool tests to assert `page`/`per_page` are correctly
  appended to the query string when provided, and omitted when not (preserving
  the exact today's-default-request shape when neither is passed).
- Manifest: `McpToolManifestTest` + a manual `node scripts/conformance.mjs`
  run against the updated manifest, same verification loop used earlier today.

## Rollout note

Single small vertical slice — one PHP file, one TypeScript file, one JSON
manifest file, all interdependent, not worth splitting further.
