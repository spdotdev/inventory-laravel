<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Shared undo-one-deletion-gesture writer — used by both
 * Http\Controllers\Api\RestoreController and the web parity twin
 * (Http\Controllers\Web\WebRestoreController), same reason HierarchyDeleter
 * and Reorderer are shared: the batch-scoping, cross-batch-parent-dead
 * refusal (C-1), and system-shelf-conflict guard (C-1's shelf twin) can never
 * drift between the two surfaces this way. Only authorization
 * (`Gate::authorize('restructure', ...)`, caller-specific) and the response
 * shape stay in the controllers.
 *
 * Keyed by batch at the HOUSEHOLD level, not by resource id — see the
 * original API controller's docblock (still accurate) for why: scoped
 * route-model binding resolves {shelf} through $location->shelves(), which
 * the SoftDeletes global scope filters, so a soft-deleted shelf 404s on every
 * nested route and a restore keyed by shelf id could never be reached.
 */
class Restorer
{
    public const STATUS_RESTORED = 'restored';

    public const STATUS_NOTHING = 'nothing';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Who ran the delete gesture that produced this batch, so
     * HouseholdPolicy::restoreBatch can let a Member restore a batch they
     * minted themselves without granting them restructure generally.
     *
     * A batch can span all three tables (a location delete cascades into its
     * shelves and products) and can include MOVED rows (still live,
     * restore_parent_id set) as well as soft-deleted ones — see Restorer's
     * class docblock for why both kinds exist. Every row in one batch was
     * stamped by the same HierarchyDeleter/controller call, so deleted_by is
     * identical across all of them; reading it off any single row in the
     * batch is enough. Returns null for an unknown/already-purged batch —
     * the caller (RestoreController/WebRestoreController) must treat that as
     * "nothing to restore", not "no one may restore it".
     */
    public static function batchOwnerId(Household $household, string $batch): ?int
    {
        $householdLocationIds = StorageLocation::withTrashed()
            ->where('household_id', $household->getKey())
            ->pluck('id');

        $householdShelfIds = Shelf::withTrashed()
            ->whereIn('location_id', $householdLocationIds)
            ->pluck('id');

        $location = StorageLocation::withTrashed()
            ->where('household_id', $household->getKey())
            ->where('deletion_batch_id', $batch)
            ->whereNotNull('deleted_by')
            ->first();

        if ($location !== null) {
            return (int) $location->deleted_by;
        }

        $shelf = Shelf::withTrashed()
            ->whereIn('location_id', $householdLocationIds)
            ->where('deletion_batch_id', $batch)
            ->whereNotNull('deleted_by')
            ->first();

        if ($shelf !== null) {
            return (int) $shelf->deleted_by;
        }

        $product = Product::withTrashed()
            ->whereIn('shelf_id', $householdShelfIds)
            ->where('deletion_batch_id', $batch)
            ->whereNotNull('deleted_by')
            ->first();

        return $product !== null ? (int) $product->deleted_by : null;
    }

    /**
     * @return array{status: string, restored: int}
     */
    public static function restore(Household $household, string $batch): array
    {
        // Everything — gathering, the C-1/C1 guards, and the writes — runs in
        // ONE transaction, and every parent row a guard depends on is read
        // under lockForUpdate(). Without the locks, a restore racing a
        // concurrent HierarchyDeleter delete of the parent could interleave
        // (both guards read pre-commit state, both commit) and strand a LIVE
        // child under a soft-deleted parent: invisible everywhere,
        // unrestorable, and hard-destroyed with the parent when the retention
        // purge fires its ON DELETE CASCADE. HierarchyDeleter takes the same
        // lock on the row it deletes, so the two gestures serialize.
        /** @var array{status: string, restored: int} $result */
        $result = DB::transaction(function () use ($household, $batch): array {
            return self::restoreWithinTransaction($household, $batch);
        });

        if ($result['status'] === self::STATUS_RESTORED) {
            HouseholdChanged::dispatch((int) $household->getKey());
        }

        return $result;
    }

    /**
     * @return array{status: string, restored: int}
     */
    private static function restoreWithinTransaction(Household $household, string $batch): array
    {
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
        // else, or one already purged by the retention job. Left for the
        // caller to turn into a 409 — the household is real, the undo just
        // isn't possible any more.
        if ($total === 0) {
            return ['status' => self::STATUS_NOTHING, 'restored' => 0];
        }

        // C-1: "parents before children" below only orders the WRITES within
        // this one batch — it says nothing about a parent killed by a
        // DIFFERENT, later batch that is still dead. The server never
        // guesses here — if any row in this batch has a parent that is dead
        // and NOT itself part of this same batch, the whole restore is
        // refused. Checked BEFORE any write, inside the transaction, so a
        // blocked restore leaves nothing partially done.
        $shelfParentLocationIds = Shelf::withTrashed()->whereKey($shelfIds)->pluck('location_id');
        $movedShelfOriginalLocationIds = Shelf::query()->whereKey($movedShelfIds)->pluck('restore_parent_id');
        $productParentShelfIds = Product::withTrashed()->whereKey($productIds)->pluck('shelf_id');
        $movedProductOriginalShelfIds = Product::query()->whereKey($movedProductIds)->pluck('restore_parent_id');

        // Lock every parent row the guards below depend on — unconditionally,
        // not only when it currently looks dead. A parent that looks LIVE here
        // may be mid-delete in a concurrent HierarchyDeleter transaction;
        // FOR UPDATE blocks until that commits (and the guard then sees the
        // corpse), or makes the deleter's own locking read wait for this
        // restore (and its child listing then sees the restored rows).
        // No-op on SQLite; real row locks on the MySQL CI job and in prod.
        StorageLocation::withTrashed()
            ->whereIn('id', $shelfParentLocationIds->merge($movedShelfOriginalLocationIds)->filter()->unique())
            ->lockForUpdate()
            ->get();
        Shelf::withTrashed()
            ->whereIn('id', $productParentShelfIds->merge($movedProductOriginalShelfIds)->filter()->unique())
            ->lockForUpdate()
            ->get();

        $shelfParentStillDead = StorageLocation::withTrashed()
            ->whereIn('id', $shelfParentLocationIds)
            ->whereNotIn('id', $locationIds)
            ->whereNotNull('deleted_at')
            ->exists();

        $productParentStillDead = Shelf::withTrashed()
            ->whereIn('id', $productParentShelfIds)
            ->whereNotIn('id', $shelfIds)
            ->whereNotNull('deleted_at')
            ->exists();

        // Same check, mirrored for MOVED rows: restore_parent_id records
        // where a shelf/product lived BEFORE the strategy reparented it.
        $movedShelfOriginalParentStillDead = StorageLocation::withTrashed()
            ->whereIn('id', $movedShelfOriginalLocationIds)
            ->whereNotIn('id', $locationIds)
            ->whereNotNull('deleted_at')
            ->exists();

        $movedProductOriginalParentStillDead = Shelf::withTrashed()
            ->whereIn('id', $movedProductOriginalShelfIds)
            ->whereNotIn('id', $shelfIds)
            ->whereNotNull('deleted_at')
            ->exists();

        // C1: a soft-deleted is_system shelf coming back to life must never
        // create a SECOND live Unsorted shelf in the location it lands in.
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
            return ['status' => self::STATUS_BLOCKED, 'restored' => 0];
        }

        // Parents first, so a restored shelf never lands under a still-deleted
        // location within THIS batch's own writes.
        StorageLocation::withTrashed()->whereKey($locationIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
        Shelf::withTrashed()->whereKey($shelfIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);
        Product::withTrashed()->whereKey($productIds)->update(['deleted_at' => null, 'deletion_batch_id' => null]);

        // Moved rows: reverse the reparenting the strategy performed. Read
        // each row's OWN restore_parent_id rather than assume one shared
        // value.
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

        return ['status' => self::STATUS_RESTORED, 'restored' => $total];
    }
}
