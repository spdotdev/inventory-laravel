<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\User;

#[Description('Search inventory users by name or email address. Returns up to 50 matches.')]
class SearchUsersTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Name or email search query.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $q = $request->get('q');

        $users = User::query()
            ->withCount('households')
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            })
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['id', 'name', 'email', 'google_id', 'created_at']);

        return Response::json($users->toArray());
    }
}
