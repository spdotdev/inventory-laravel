<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spdotdev\Inventory\Models\Product;

class MissingItemsController
{
    public function count(Request $request): JsonResponse
    {
        $count = Product::query()
            ->where('is_mandatory', true)
            ->where('quantity', 0)
            ->whereHas('shelf.location.household.users', function ($query) use ($request) {
                $query->where('inventory_users.id', $request->user()->id);
            })
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }
}
