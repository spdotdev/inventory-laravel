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

        // C-1: "parents before children" below only orders the WRITES within
        // this one batch — it says nothing about a parent killed by a
        // DIFFERENT, later batch that is still dead. E.g. delete a shelf
        // (batch A), then delete its location with delete_contents (batch
        // B) — HierarchyDeleter's $location->shelves() is scoped by the
        // SoftDeletes global scope, so the already-trashed shelf is skipped
        // and keeps batch A while only the location is stamped B. Restoring
        // A alone would resurrect the shelf (and its products) under a
        // location that is still very much dead: a "200 Restored" that
        // restores nothing visible, and a live row the retention purge would
        // later CASCADE-kill permanently once it force-deletes the still-
        // trashed location. The server never guesses here — if any row in
        // this batch has a parent that is dead and NOT itself part of this
        // same batch (i.e. not something this call is about to restore too),
        // the whole restore is refused. Checked BEFORE any write, inside the
        // transaction, so a blocked restore leaves nothing partially done.
        $blocked = DB::transaction(function () use ($locationIds, $shelfIds, $productIds): bool {
            $shelfParentLocationIds = Shelf::withTrashed()->whereKey($shelfIds)->pluck('location_id');

            $shelfParentStillDead = StorageLocation::withTrashed()
                ->whereIn('id', $shelfParentLocationIds)
                ->whereNotIn('id', $locationIds)
                ->whereNotNull('deleted_at')
                ->exists();

            $productParentShelfIds = Product::withTrashed()->whereKey($productIds)->pluck('shelf_id');

            $productParentStillDead = Shelf::withTrashed()
                ->whereIn('id', $productParentShelfIds)
                ->whereNotIn('id', $shelfIds)
                ->whereNotNull('deleted_at')
                ->exists();

            if ($shelfParentStillDead || $productParentStillDead) {
                return true;
            }

            // Parents first, so a restored shelf never lands under a still-deleted
            // location within THIS batch's own writes.
            StorageLocation::withTrashed()->whereKey($locationIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
            Shelf::withTrashed()->whereKey($shelfIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
            Product::withTrashed()->whereKey($productIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);

            return false;
        });

        if ($blocked) {
            return response()->json([
                'message' => 'Cannot restore: a parent of one of these rows is still deleted under a different batch. Restore the parent first.',
            ], 409);
        }

        HouseholdChanged::dispatch((int) $household->getKey());

        return response()->json([
            'message' => 'Restored.',
            'restored' => $total,
        ]);
    }
}
