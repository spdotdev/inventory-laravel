<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

#[Description('Get full details for a user including their households and joined dates.')]
class GetUserTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('User ID.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = User::query()->with('households')->findOrFail($request->get('id'));

        return Response::json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'google_id' => $user->google_id,
            'avatar_url' => $user->avatar_url,
            'created_at' => $user->created_at,
            'households' => $user->households->map(fn (Household $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'join_code' => $h->join_code,
                'joined_at' => $h->pivot?->joined_at,
            ]),
        ]);
    }
}
