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
