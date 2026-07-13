<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Enums\LocationDeleteStrategy;
use Spdotdev\Inventory\Enums\ShelfDeleteStrategy;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Executes a structural delete as one transaction, stamping every row it kills
 * with the caller's batch id so the whole gesture can be restored as a unit.
 *
 * Everything INSIDE the transaction is a query-builder write — which fires NO
 * Eloquent events, and therefore never reaches the BroadcastHouseholdChange
 * observer. That is deliberate (one deterministic ping beats N model events),
 * but it means this class MUST dispatch HouseholdChanged itself. It does,
 * once, after commit — see the resolution of the Unsorted shelf's id below,
 * which is hoisted ABOVE the transaction for exactly this reason: it goes
 * through Eloquent's create(), which DOES fire events.
 */
class HierarchyDeleter
{
    /**
     * Delete a shelf, doing what the caller asked with its products.
     *
     * @throws ValidationException when the move target is invalid
     */
    public static function deleteShelf(
        Household $household,
        Shelf $shelf,
        string $batchId,
        ?ShelfDeleteStrategy $strategy,
        ?int $targetShelfId,
    ): void {
        // Resolved to an id, not a model, so the closure below cannot be handed a
        // half-validated Shelf: non-null here means "validated, move there".
        $moveToShelfId = null;

        if ($strategy === ShelfDeleteStrategy::MoveProducts) {
            // Must be a live shelf of the SAME household, and not the shelf we
            // are about to delete (which would strand the products on a corpse).
            $target = $household->shelves()->whereKey($targetShelfId)->first();

            if ($target === null || (int) $target->getKey() === (int) $shelf->getKey()) {
                throw ValidationException::withMessages([
                    'target_shelf_id' => ['Pick a different shelf in this household.'],
                ]);
            }

            $moveToShelfId = (int) $target->getKey();
        }

        // Resolved to a plain id BEFORE the transaction opens, mirroring the
        // move-target handling above. unsortedShelf() is a find-or-create that
        // goes through Eloquent's create() on a miss, which fires the
        // `created` model event -> BroadcastHouseholdChange -> an in-flight
        // HouseholdChanged::dispatch() while our own transaction is still
        // open. If the transaction later rolled back, that broadcast would
        // already be irreversibly sent — every other member's client would be
        // told the tree changed when it did not. Doing this here means any
        // failure surfaces before a single row of THIS delete is touched; an
        // orphaned empty Unsorted shelf left behind by a failed delete is
        // harmless — it is disposable and gets reused/recreated on demand.
        //
        // Shelf::withoutEvents(...) additionally suppresses that `created`
        // event's own broadcast (scoped to just this call — every OTHER
        // caller of unsortedShelf(), e.g. a plain reorder, still gets the
        // normal ping for a shelf appearing). Without this, a first-use
        // unsort delete would broadcast TWICE for one user gesture: once for
        // the Unsorted shelf being created, once for the delete itself. This
        // class already promises its own single, deterministic ping after
        // commit — that ping is sufficient for every client to know to
        // refetch, so the incidental creation doesn't need a second one.
        $unsortedShelfId = $strategy === ShelfDeleteStrategy::UnsortProducts
            ? (int) Shelf::withoutEvents(fn () => $shelf->location->unsortedShelf())->getKey()
            : null;

        DB::transaction(function () use ($shelf, $batchId, $strategy, $moveToShelfId, $unsortedShelfId) {
            $now = now();

            if ($moveToShelfId !== null) {
                $shelf->products()->update(['shelf_id' => $moveToShelfId]);
            }

            if ($unsortedShelfId !== null) {
                $shelf->products()->update(['shelf_id' => $unsortedShelfId]);
            }

            if ($strategy === ShelfDeleteStrategy::DeleteProducts) {
                $shelf->products()->update([
                    'deleted_at' => $now,
                    'deletion_batch_id' => $batchId,
                ]);
            }

            $shelf->newQuery()->whereKey($shelf->getKey())->update([
                'deleted_at' => $now,
                'deletion_batch_id' => $batchId,
            ]);
        });

        HouseholdChanged::dispatch((int) $household->getKey());
    }

    /**
     * Delete a location, doing what the caller asked with its contents.
     *
     * There is deliberately no "unsort" branch here (see LocationDeleteStrategy):
     * unsorted means off-shelf but still IN this location, and the location is
     * the thing being deleted. Only move-elsewhere or delete-with-it are coherent.
     *
     * @throws ValidationException when the move target is invalid
     */
    public static function deleteLocation(
        Household $household,
        StorageLocation $location,
        string $batchId,
        ?LocationDeleteStrategy $strategy,
        ?int $targetLocationId,
    ): void {
        // Resolved to an id, not a model, so the closure below cannot be handed a
        // half-validated StorageLocation: non-null here means "validated, move there".
        $moveToLocationId = null;

        if ($strategy === LocationDeleteStrategy::MoveContents) {
            // Must be a live location of the SAME household, and not the location
            // we are about to delete (which would strand the shelves on a corpse).
            $target = $household->locations()->whereKey($targetLocationId)->first();

            if ($target === null || (int) $target->getKey() === (int) $location->getKey()) {
                throw ValidationException::withMessages([
                    'target_location_id' => ['Pick a different location in this household.'],
                ]);
            }

            $moveToLocationId = (int) $target->getKey();
        }

        DB::transaction(function () use ($location, $batchId, $strategy, $moveToLocationId) {
            $now = now();

            if ($moveToLocationId !== null) {
                // Reparent the shelves. Products hang off the shelf and never
                // change identity, so they ride along without being touched.
                $location->shelves()->update(['location_id' => $moveToLocationId]);
            }

            if ($strategy === LocationDeleteStrategy::DeleteContents) {
                // Read, not write — safe to run pre-commit (see class docblock).
                $shelfIds = $location->shelves()->pluck('id')->all();

                Product::query()->whereIn('shelf_id', $shelfIds)->update([
                    'deleted_at' => $now,
                    'deletion_batch_id' => $batchId,
                ]);

                $location->shelves()->update([
                    'deleted_at' => $now,
                    'deletion_batch_id' => $batchId,
                ]);
            }

            $location->newQuery()->whereKey($location->getKey())->update([
                'deleted_at' => $now,
                'deletion_batch_id' => $batchId,
            ]);
        });

        HouseholdChanged::dispatch((int) $household->getKey());
    }
}
