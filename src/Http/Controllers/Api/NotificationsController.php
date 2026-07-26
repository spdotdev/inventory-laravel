<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spdotdev\Inventory\Models\AppNotification;
use Spdotdev\Inventory\Models\Household;

class NotificationsController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(Request $request): JsonResponse
    {
        $after = max(0, (int) $request->input('after', 0));

        $rows = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get();

        $households = Household::query()
            ->whereIn('id', $rows->pluck('household_id')->filter()->unique())
            ->pluck('name', 'id');

        return response()->json([
            'data' => $rows->map(fn (AppNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'household' => $n->household_id !== null
                    ? ['id' => $n->household_id, 'name' => $households[$n->household_id] ?? null]
                    : null,
                'payload' => $n->payload,
                'created_at' => $n->created_at->toIso8601String(),
            ])->all(),
            'meta' => ['last_id' => $rows->last()->id ?? $after],
        ]);
    }
}
