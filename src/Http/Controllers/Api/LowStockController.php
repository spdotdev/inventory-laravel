<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spdotdev\Inventory\Models\Product;

class LowStockController extends Controller
{
    public function count(Request $request): JsonResponse
    {
        $count = Product::query()
            ->whereNotNull('low_stock_threshold')
            ->whereColumn('quantity', '<=', 'low_stock_threshold')
            ->whereNot(function ($query) {
                $query->where('is_mandatory', true)->where('quantity', 0);
            })
            ->whereHas('shelf.location.household.users', function ($query) use ($request) {
                $query->where('inventory_users.id', $request->user()->id);
            })
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }
}
