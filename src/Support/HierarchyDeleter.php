<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Enums\ShelfDeleteStrategy;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;

/**
 * Executes a structural delete as one transaction, stamping every row it kills
 * with the caller's batch id so the whole gesture can be restored as a unit.
 *
 * Everything here is a query-builder write — which fires NO Eloquent events, and
 * therefore never reaches the BroadcastHouseholdChange observer. That is
 * deliberate (one deterministic ping beats N model events), but it means this
 * class MUST dispatch HouseholdChanged itself. It does, once, after commit.
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

        DB::transaction(function () use ($shelf, $batchId, $strategy, $moveToShelfId) {
            $now = now();

            if ($moveToShelfId !== null) {
                $shelf->products()->update(['shelf_id' => $moveToShelfId]);
            }

            if ($strategy === ShelfDeleteStrategy::UnsortProducts) {
                $unsorted = $shelf->location->unsortedShelf();
                $shelf->products()->update(['shelf_id' => $unsorted->getKey()]);
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
}
