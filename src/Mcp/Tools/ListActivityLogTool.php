<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Http\Resources\ActivityLogEntryResource;
use Spdotdev\Inventory\Models\ActivityLogEntry;

#[Description('List activity-log entries across all households, newest first. Filterable by household_id, actor_id, action, subject_type, and date range.')]
class ListActivityLogTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'household_id' => $schema->integer()->description('Filter to one household.'),
            'actor_id' => $schema->integer()->description('Filter to entries by one acting user.'),
            'action' => $schema->string()->description('Filter to one action, e.g. product.deleted.'),
            'subject_type' => $schema->string()->description('Filter to one subject type, e.g. Product.'),
            'from' => $schema->string()->description('ISO 8601 date/time lower bound (inclusive).'),
            'to' => $schema->string()->description('ISO 8601 date/time upper bound (inclusive).'),
            'page' => $schema->integer()->description('Page number, 1-indexed (default 1).'),
            'per_page' => $schema->integer()->description('Rows per page, max 100 (default 50).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 50)));

        $query = ActivityLogEntry::query()->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        if ($request->get('household_id') !== null) {
            $query->where('household_id', (int) $request->get('household_id'));
        }
        if ($request->get('actor_id') !== null) {
            $query->where('actor_id', (int) $request->get('actor_id'));
        }
        if ($request->get('action') !== null) {
            $query->where('action', (string) $request->get('action'));
        }
        if ($request->get('subject_type') !== null) {
            $query->where('subject_type', (string) $request->get('subject_type'));
        }
        if ($request->get('from') !== null) {
            $query->where('created_at', '>=', (string) $request->get('from'));
        }
        if ($request->get('to') !== null) {
            $query->where('created_at', '<=', (string) $request->get('to'));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return Response::json([
            'data' => ActivityLogEntryResource::collection($paginator->items())->resolve(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
