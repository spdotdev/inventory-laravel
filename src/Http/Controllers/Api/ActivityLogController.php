<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spdotdev\Inventory\Http\Resources\ActivityLogEntryResource;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Models\Household;

/**
 * Read-only per-household activity feed. MCP-only surface (see the
 * household-activity-log design spec) — Android/web never call this;
 * `household.member` gates it exactly like DeletedBatchController, so any
 * member (not only Owner/Admin) can view their own household's history.
 */
class ActivityLogController
{
    public function __invoke(Request $request, Household $household): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

        $query = ActivityLogEntry::query()
            ->where('household_id', $household->getKey())
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('actor_id')) {
            $query->where('actor_id', (int) $request->input('actor_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', (string) $request->input('action'));
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', (string) $request->input('subject_type'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', (string) $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', (string) $request->input('to'));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
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
