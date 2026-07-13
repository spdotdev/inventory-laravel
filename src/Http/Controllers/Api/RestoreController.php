<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Undo one deletion gesture.
 *
 * Keyed by batch at the HOUSEHOLD level, not by resource id, because scoped
 * route-model binding resolves {shelf} through $location->shelves() — which the
 * SoftDeletes global scope filters. A soft-deleted shelf therefore 404s on every
 * nested route, so a restore keyed by shelf id could never be reached at all.
 */
class RestoreController
{
    public function __invoke(Household $household, string $batch): JsonResponse
    {
        Gate::authorize('restructure', $household);

        $locationIds = StorageLocation::withTrashed()
            ->where('household_id', $household->getKey())
            ->whereNotNull('deleted_at')
            ->where('deletion_batch_id', $batch)
            ->pluck('id');

        // Shelves and products carry no household_id, so scope them by walking
        // down from the household's own locations. A batch id is client-minted
        // and therefore guessable — never trust it alone.
        $householdLocationIds = StorageLocation::withTrashed()
            ->where('household_id', $household->getKey())
            ->pluck('id');

        $shelfIds = Shelf::withTrashed()
            ->whereIn('location_id', $householdLocationIds)
            ->whereNotNull('deleted_at')
            ->where('deletion_batch_id', $batch)
            ->pluck('id');

        $householdShelfIds = Shelf::withTrashed()
            ->whereIn('location_id', $householdLocationIds)
            ->pluck('id');

        $productIds = Product::withTrashed()
            ->whereIn('shelf_id', $householdShelfIds)
            ->whereNotNull('deleted_at')
            ->where('deletion_batch_id', $batch)
            ->pluck('id');

        $total = $locationIds->count() + $shelfIds->count() + $productIds->count();

        // Nothing to restore: an unknown batch, a batch belonging to someone
        // else, or one already purged by the retention job. 409 rather than 404
        // — the household is real, the undo just isn't possible any more.
        if ($total === 0) {
            return response()->json([
                'message' => 'Nothing to restore. This was already restored, or permanently removed.',
            ], 409);
        }

        DB::transaction(function () use ($locationIds, $shelfIds, $productIds) {
            // Parents first, so a restored shelf never lands under a still-deleted
            // location.
            StorageLocation::withTrashed()->whereKey($locationIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
            Shelf::withTrashed()->whereKey($shelfIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
            Product::withTrashed()->whereKey($productIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
        });

        HouseholdChanged::dispatch((int) $household->getKey());

        return response()->json([
            'message' => 'Restored.',
            'restored' => $total,
        ]);
    }
}
