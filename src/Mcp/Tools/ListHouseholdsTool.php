<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\Household;

#[Description('List all households with member, location, and shelf counts, ordered newest first.')]
class ListHouseholdsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $households = Household::query()
            ->withCount(['users', 'storageLocations', 'shelves'])
            ->orderBy('created_at', 'desc')
            ->get();

        return Response::json($households->map(fn (Household $h) => [
            'id' => $h->id,
            'name' => $h->name,
            'join_code' => $h->join_code,
            'created_at' => $h->created_at,
            'members' => $h->users_count,
            'locations' => $h->storage_locations_count,
            'shelves' => $h->shelves_count,
        ])->toArray());
    }
}
