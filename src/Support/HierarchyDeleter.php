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
 * Rows a strategy MOVES rather than kills are stamped too — same batch id,
 * plus restore_parent_id recording where they came from — so Undo can put
 * them back (see RestoreController).
 *
 * Everything INSIDE the transaction is a query-builder write — which fires NO
 * Eloquent events, and therefore never reaches the BroadcastHouseholdChange
 * observer. That is deliberate (one deterministic ping beats N model events),
 * but it means this class MUST dispatch HouseholdChanged itself. It does,
 * once, after commit — see the resolution of the Unsorted shelf's id below,
 * which is hoisted ABOVE the transaction. unsortedShelf() never broadcasts on
 * its own (see its docblock) regardless of who calls it or how, so hoisting it
 * above the transaction is purely about failing BEFORE any row of this delete
 * is touched, not about event suppression — an orphaned empty Unsorted shelf
 * left behind by a failed delete is harmless, disposable, reused/recreated on
 * demand.
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
        int $deletedBy,
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
                    'target_shelf_id' => [__('Pick a different shelf in this household.')],
                ]);
            }

            $moveToShelfId = (int) $target->getKey();
        }

        // Resolved to a plain id BEFORE the transaction opens, mirroring the
        // move-target handling above. unsortedShelf() never broadcasts on its
        // own (see its docblock), so this hoist is purely about failing
        // before a single row of THIS delete is touched; an orphaned empty
        // Unsorted shelf left behind by a failed delete is harmless — it is
        // disposable and gets reused/recreated on demand.
        $unsortedShelfId = $strategy === ShelfDeleteStrategy::UnsortProducts
            ? (int) $shelf->location->unsortedShelf()->getKey()
            : null;

        // The shelf's own id, captured before the transaction reassigns its
        // products elsewhere: it is every moved product's ORIGINAL parent,
        // needed below to stamp restore_parent_id (C2) so Undo can put them
        // back on THIS shelf specifically, not wherever the strategy sent them.
        $originalShelfId = (int) $shelf->getKey();

        DB::transaction(function () use ($shelf, $batchId, $strategy, $moveToShelfId, $unsortedShelfId, $originalShelfId, $deletedBy) {
            $now = now();

            // Serialize against Restorer: a concurrent restore of a product
            // whose parent is THIS shelf locks this row (see Restorer's
            // parent locks) — locking it here too means one of the two
            // gestures fully commits before the other reads, so a restore
            // can never land a live product under the corpse this
            // transaction is about to create. No-op on SQLite.
            Shelf::withTrashed()->whereKey($shelf->getKey())->lockForUpdate()->get();

            // move_products / unsort_products both REPARENT the products
            // rather than kill them — they stay live. C2: stamp the batch id
            // (so a live row can still be located as part of this batch) and
            // restore_parent_id (so RestoreController knows to put shelf_id
            // back to $originalShelfId, not merely clear a soft-delete that
            // never happened). deleted_by rides along too, since these rows
            // are as much part of THIS gesture as any soft-deleted row below
            // — Restorer::batchOwnerId reads whichever row in the batch it
            // finds first, moved or killed.
            if ($moveToShelfId !== null) {
                $shelf->products()->update([
                    'shelf_id' => $moveToShelfId,
                    'deletion_batch_id' => $batchId,
                    'restore_parent_id' => $originalShelfId,
                    'deleted_by' => $deletedBy,
                ]);
            }

            if ($unsortedShelfId !== null) {
                $shelf->products()->update([
                    'shelf_id' => $unsortedShelfId,
                    'deletion_batch_id' => $batchId,
                    'restore_parent_id' => $originalShelfId,
                    'deleted_by' => $deletedBy,
                ]);
            }

            if ($strategy === ShelfDeleteStrategy::DeleteProducts) {
                $shelf->products()->update([
                    'deleted_at' => $now,
                    'deletion_batch_id' => $batchId,
                    'deleted_by' => $deletedBy,
                ]);
            }

            $shelf->newQuery()->whereKey($shelf->getKey())->update([
                'deleted_at' => $now,
                'deletion_batch_id' => $batchId,
                'deleted_by' => $deletedBy,
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
        int $deletedBy,
    ): void {
        // Resolved to an id, not a model, so the closure below cannot be handed a
        // half-validated StorageLocation: non-null here means "validated, move there".
        $moveToLocationId = null;

        // The source location's own Unsorted shelves (if it has any — see
        // below for why this is plural) and the id of the target's Unsorted
        // shelf they may need to merge into. Both resolved to plain ids ABOVE
        // the transaction; the target one through unsortedShelf(), which
        // never broadcasts on its own (see its docblock) — this hoist is
        // purely about failing before a single row of THIS delete is touched.
        //
        // C1: gathered as EVERY is_system shelf of the source, not
        // ->first(). In a correctly-maintained tree there is at most one —
        // but a location that has already lived through the
        // delete-empty-Unsorted / recreate-it cycle a few times, combined
        // with an Undo landing awkwardly, could still have more than one
        // lying around from before this fix shipped (see
        // StorageLocation::unsortedShelf() and
        // docs/superpowers/sdd/final-review-fixes.md, C1). ->first() would
        // silently strand every OTHER one: neither reparented, nor merged,
        // nor soft-deleted, nor batch-stamped — live, with its products, under
        // a location this call is about to soft-delete, invisible to search,
        // unrestorable, and permanently destroyed the day the retention purge
        // force-deletes the parent and ON DELETE CASCADE fires.
        $sourceUnsortedShelfIds = [];
        $targetUnsortedShelfId = null;

        if ($strategy === LocationDeleteStrategy::MoveContents) {
            // Must be a live location of the SAME household, and not the location
            // we are about to delete (which would strand the shelves on a corpse).
            $target = $household->locations()->whereKey($targetLocationId)->first();

            if ($target === null || (int) $target->getKey() === (int) $location->getKey()) {
                throw ValidationException::withMessages([
                    'target_location_id' => [__('Pick a different location in this household.')],
                ]);
            }

            $moveToLocationId = (int) $target->getKey();

            // The Unsorted shelf(s) are never reparented as-is: a plain
            // location_id update would carry them into the target still
            // flagged is_system, and if the target already has its own
            // Unsorted shelf that leaves TWO+ live is_system shelves there —
            // the exact state StorageLocation::unsortedShelf() exists to
            // prevent, reached through a different door. So they always stay
            // behind and die with the rest of this batch; only their
            // PRODUCTS (if any) are rescued, into the target's own Unsorted
            // shelf.
            $sourceUnsortedShelfIds = $location->shelves()->where('is_system', true)->pluck('id')->all();

            if ($sourceUnsortedShelfIds !== []) {
                $hasProducts = Product::query()->whereIn('shelf_id', $sourceUnsortedShelfIds)->exists();

                if ($hasProducts) {
                    $targetUnsortedShelfId = (int) $target->unsortedShelf()->getKey();
                }
            }
        }

        // The location's own id, captured before the transaction reparents its
        // shelves elsewhere: it is every moved shelf's ORIGINAL parent, needed
        // below to stamp restore_parent_id (C2) so Undo can put them back on
        // THIS location specifically.
        $originalLocationId = (int) $location->getKey();

        DB::transaction(function () use ($location, $batchId, $strategy, $moveToLocationId, $originalLocationId, $sourceUnsortedShelfIds, $targetUnsortedShelfId, $deletedBy) {
            $now = now();

            // Serialize against Restorer — same reasoning as deleteShelf's
            // lock above: a restore bringing a shelf back under THIS
            // location locks this row first, so delete_contents' child
            // listing below either runs after that restore committed (and
            // stamps the restored shelf into this batch) or fully commits
            // first (and the restore's guard sees the dead parent and
            // refuses). No-op on SQLite.
            StorageLocation::withTrashed()->whereKey($location->getKey())->lockForUpdate()->get();

            if ($moveToLocationId !== null) {
                // Reparent only the location's non-system shelves. Products
                // hang off the shelf and never change identity, so they ride
                // along without being touched. C2: stamp the batch id (so a
                // live row can still be located as part of this batch) and
                // restore_parent_id (so RestoreController knows to put
                // location_id back to $originalLocationId on Undo).
                $location->shelves()->where('is_system', false)->update([
                    'location_id' => $moveToLocationId,
                    'deletion_batch_id' => $batchId,
                    'restore_parent_id' => $originalLocationId,
                    'deleted_by' => $deletedBy,
                ]);

                if ($sourceUnsortedShelfIds !== []) {
                    if ($targetUnsortedShelfId !== null) {
                        // Per source shelf, not a single bulk update: each
                        // rescued product's restore_parent_id must point back
                        // at the SPECIFIC source Unsorted shelf it came from
                        // (C2), not merely at "the" source shelf — there can
                        // be more than one (see C1 above).
                        foreach ($sourceUnsortedShelfIds as $sourceUnsortedShelfId) {
                            Product::query()->where('shelf_id', $sourceUnsortedShelfId)->update([
                                'shelf_id' => $targetUnsortedShelfId,
                                'deletion_batch_id' => $batchId,
                                'restore_parent_id' => $sourceUnsortedShelfId,
                                'deleted_by' => $deletedBy,
                            ]);
                        }
                    }

                    // Now empty (or already was) — dies with this batch
                    // instead of dangling, live, under the parent this
                    // transaction is about to soft-delete.
                    Shelf::query()->whereKey($sourceUnsortedShelfIds)->update([
                        'deleted_at' => $now,
                        'deletion_batch_id' => $batchId,
                        'deleted_by' => $deletedBy,
                    ]);
                }
            }

            if ($strategy === LocationDeleteStrategy::DeleteContents) {
                // Read, not write — safe to run pre-commit (see class docblock).
                $shelfIds = $location->shelves()->pluck('id')->all();

                Product::query()->whereIn('shelf_id', $shelfIds)->update([
                    'deleted_at' => $now,
                    'deletion_batch_id' => $batchId,
                    'deleted_by' => $deletedBy,
                ]);

                $location->shelves()->update([
                    'deleted_at' => $now,
                    'deletion_batch_id' => $batchId,
                    'deleted_by' => $deletedBy,
                ]);
            }

            $location->newQuery()->whereKey($location->getKey())->update([
                'deleted_at' => $now,
                'deletion_batch_id' => $batchId,
                'deleted_by' => $deletedBy,
            ]);
        });

        HouseholdChanged::dispatch((int) $household->getKey());
    }
}
