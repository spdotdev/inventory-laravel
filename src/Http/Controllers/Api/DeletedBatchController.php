<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Support\RecentlyDeleted;

/**
 * Read-only listing of a household's restorable deletion batches — the
 * API/Android twin of the web's "Recently deleted" section
 * (Http\Controllers\Web\WebHouseholdController::show). Any member can view
 * (matches the web's authorizeMember, not the restore/restructure gate),
 * since this is informational, not destructive.
 */
class DeletedBatchController
{
    public function __invoke(Household $household): JsonResponse
    {
        return response()->json([
            'data' => RecentlyDeleted::forHousehold($household)->values(),
        ]);
    }
}
