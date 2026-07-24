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
            ->orderBy('id', 'desc')
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
