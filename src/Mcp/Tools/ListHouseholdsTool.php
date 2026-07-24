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
            ->orderBy('id', 'desc')
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
