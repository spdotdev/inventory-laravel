<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\Household;

#[Description('Permanently delete a household and cascade-delete all its locations, shelves, and products.')]
class DeleteHouseholdTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Household ID to delete.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = $request->get('id');
        $household = Household::query()->findOrFail($id);
        $name = $household->name;
        $household->delete();

        return Response::text("Household #{$id} ({$name}) has been deleted along with all its contents.");
    }
}
