<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\User;

#[Description('List all registered inventory users with their household counts, ordered newest first.')]
class ListUsersTool extends Tool
{
    public function handle(Request $request): Response
    {
        $users = User::query()
            ->withCount('households')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'email', 'google_id', 'created_at', 'households_count']);

        return Response::json($users->toArray());
    }
}
