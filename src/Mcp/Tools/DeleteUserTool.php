<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\User;

#[Description('Permanently delete an inventory user. Their household memberships are removed. Households they owned are cascade-deleted with all locations, shelves, and products.')]
class DeleteUserTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('User ID to delete.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = $request->get('id');
        $user = User::query()->findOrFail($id);
        $name = $user->name;
        $user->delete();

        return Response::text("User #{$id} ({$name}) has been deleted.");
    }
}
