<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

#[Description('Get full details for a household including members and storage locations with shelf counts.')]
class GetHouseholdTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Household ID.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $household = Household::query()
            ->with(['users', 'locations.shelves'])
            ->withCount(['users', 'locations', 'shelves'])
            ->findOrFail($request->get('id'));

        return Response::json([
            'id' => $household->id,
            'name' => $household->name,
            'join_code' => $household->join_code,
            'created_at' => $household->created_at,
            'members' => $household->users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'joined_at' => $u->pivot?->joined_at,
            ]),
            'locations' => $household->locations->map(fn ($loc) => [
                'id' => $loc->id,
                'name' => $loc->name,
                'shelf_count' => $loc->shelves->count(),
            ]),
        ]);
    }
}
