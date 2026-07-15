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

        // C2: move_products / unsort_products / move_contents reparent a row
        // instead of killing it — it stays LIVE, just stamped with this batch
        // id and a restore_parent_id recording where it came from (see
        // HierarchyDeleter). The whereNotNull('deleted_at') queries above are
        // blind to these rows by construction — gather them separately, or
        // Undo silently does nothing for every gesture that moved instead of
        // deleted.
        $movedShelfIds = Shelf::query()
            ->whereIn('location_id', $householdLocationIds)
            ->whereNotNull('restore_parent_id')
            ->where('deletion_batch_id', $batch)
            ->pluck('id');

        $movedProductIds = Product::query()
            ->whereIn('shelf_id', $householdShelfIds)
            ->whereNotNull('restore_parent_id')
            ->where('deletion_batch_id', $batch)
            ->pluck('id');

        $total = $locationIds->count() + $shelfIds->count() + $productIds->count()
            + $movedShelfIds->count() + $movedProductIds->count();

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
        $blocked = DB::transaction(function () use (
            $locationIds,
            $shelfIds,
            $productIds,
            $movedShelfIds,
            $movedProductIds,
        ): bool {
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

            // Same check, mirrored for MOVED rows: restore_parent_id records
            // where a shelf/product lived BEFORE the strategy reparented it.
            // If that original parent is itself dead under a DIFFERENT batch
            // (e.g. the now-empty source shelf/location was separately
            // deleted after the move), writing the row back there would
            // resurrect it under a parent this restore never touches.
            $movedShelfOriginalLocationIds = Shelf::query()->whereKey($movedShelfIds)->pluck('restore_parent_id');

            $movedShelfOriginalParentStillDead = StorageLocation::withTrashed()
                ->whereIn('id', $movedShelfOriginalLocationIds)
                ->whereNotIn('id', $locationIds)
                ->whereNotNull('deleted_at')
                ->exists();

            $movedProductOriginalShelfIds = Product::query()->whereKey($movedProductIds)->pluck('restore_parent_id');

            $movedProductOriginalParentStillDead = Shelf::withTrashed()
                ->whereIn('id', $movedProductOriginalShelfIds)
                ->whereNotIn('id', $shelfIds)
                ->whereNotNull('deleted_at')
                ->exists();

            // C1: a soft-deleted is_system shelf coming back to life must never
            // create a SECOND live Unsorted shelf in the location it lands in
            // — the exact state StorageLocation::unsortedShelf() itself now
            // refuses to produce on its own door (see its docblock). This
            // closes the same door reached via Undo instead: e.g. an old,
            // never-purged batch that still references a since-superseded
            // Unsorted shelf.
            $systemShelfConflict = Shelf::withTrashed()
                ->whereKey($shelfIds)
                ->where('is_system', true)
                ->get()
                ->contains(fn (Shelf $shelf): bool => Shelf::query()
                    ->where('location_id', $shelf->location_id)
                    ->where('is_system', true)
                    ->whereKeyNot($shelf->getKey())
                    ->exists());

            if ($shelfParentStillDead || $productParentStillDead
                || $movedShelfOriginalParentStillDead || $movedProductOriginalParentStillDead
                || $systemShelfConflict) {
                return true;
            }

            // Parents first, so a restored shelf never lands under a still-deleted
            // location within THIS batch's own writes.
            StorageLocation::withTrashed()->whereKey($locationIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
            Shelf::withTrashed()->whereKey($shelfIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
            Product::withTrashed()->whereKey($productIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);

            // Moved rows: reverse the reparenting the strategy performed.
            // Read each row's OWN restore_parent_id rather than assume one
            // shared value — a location's move_contents, say, stamps every
            // moved shelf from the same source, but nothing about the column
            // itself guarantees that in general.
            foreach (Shelf::query()->whereKey($movedShelfIds)->get() as $movedShelf) {
                Shelf::query()->whereKey($movedShelf->getKey())->update([
                    'location_id' => $movedShelf->restore_parent_id,
                    'restore_parent_id' => null,
                    'deletion_batch_id' => null,
                ]);
            }

            foreach (Product::query()->whereKey($movedProductIds)->get() as $movedProduct) {
                Product::query()->whereKey($movedProduct->getKey())->update([
                    'shelf_id' => $movedProduct->restore_parent_id,
                    'restore_parent_id' => null,
                    'deletion_batch_id' => null,
                ]);
            }

            return false;
        });

        if ($blocked) {
            return response()->json([
                'message' => 'Cannot restore: a parent of one of these rows is still deleted under a different batch, or restoring would create a second live Unsorted shelf. Restore the parent first.',
            ], 409);
        }

        HouseholdChanged::dispatch((int) $household->getKey());

        return response()->json([
            'message' => 'Restored.',
            'restored' => $total,
        ]);
    }
}
