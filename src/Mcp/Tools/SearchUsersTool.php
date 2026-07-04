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
        $q = (string) $request->get('q');

        // Escape LIKE wildcards so a literal % / _ is matched literally rather than
        // acting as a wildcard (correct results). Portable ESCAPE '!' — SQLite (CI)
        // doesn't treat backslash as a LIKE escape by default, unlike MySQL. Same
        // escaping as AdminController::searchUsers (W11); '!' is escaped first.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);

        $users = User::query()
            ->withCount('households')
            ->where(function ($query) use ($escaped) {
                $query->whereRaw("name LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                    ->orWhereRaw("email LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
            })
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['id', 'name', 'email', 'google_id', 'created_at']);

        return Response::json($users->toArray());
    }
}
