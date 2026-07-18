<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Read-only listing of a household's restorable deletion batches — GAP-6 H5's
 * cross-surface-recovery closer: whichever surface (API/Android or web)
 * minted a batch, this lists it, because it queries the soft-deleted rows
 * directly rather than any per-surface log.
 *
 * Every hierarchy delete gesture soft-deletes at least one row (the
 * top-level entity the user chose to delete always dies; a MOVE strategy
 * only changes what happens to its *contents* — see HierarchyDeleter), so
 * grouping the trashed locations/shelves/products by `deletion_batch_id` is
 * a complete listing: nothing restorable is reachable only through a moved
 * (still-live) row.
 */
class RecentlyDeleted
{
    /**
     * @return Collection<int, array{batch: string, deleted_at: Carbon, locations: int, shelves: int, products: int, total: int}>
     */
    public static function forHousehold(Household $household): Collection
    {
        $cutoff = self::cutoff();

        $locationRows = StorageLocation::withTrashed()
            ->where('household_id', $household->getKey())
            ->whereNotNull('deleted_at')
            ->whereNotNull('deletion_batch_id')
            ->when($cutoff !== null, fn ($q) => $q->where('deleted_at', '>=', $cutoff))
            ->get(['deletion_batch_id', 'deleted_at']);

        $householdLocationIds = StorageLocation::withTrashed()
            ->where('household_id', $household->getKey())
            ->pluck('id');

        $shelfRows = Shelf::withTrashed()
            ->whereIn('location_id', $householdLocationIds)
            ->whereNotNull('deleted_at')
            ->whereNotNull('deletion_batch_id')
            ->when($cutoff !== null, fn ($q) => $q->where('deleted_at', '>=', $cutoff))
            ->get(['deletion_batch_id', 'deleted_at']);

        $householdShelfIds = Shelf::withTrashed()
            ->whereIn('location_id', $householdLocationIds)
            ->pluck('id');

        $productRows = Product::withTrashed()
            ->whereIn('shelf_id', $householdShelfIds)
            ->whereNotNull('deleted_at')
            ->whereNotNull('deletion_batch_id')
            ->when($cutoff !== null, fn ($q) => $q->where('deleted_at', '>=', $cutoff))
            ->get(['deletion_batch_id', 'deleted_at']);

        /** @var array<string, array{batch: string, deleted_at: Carbon, locations: int, shelves: int, products: int}> $batches */
        $batches = [];

        foreach ([
            ['locations', $locationRows],
            ['shelves', $shelfRows],
            ['products', $productRows],
        ] as [$key, $rows]) {
            foreach ($rows as $row) {
                $batch = (string) $row->deletion_batch_id;
                // The queries above all filter whereNotNull('deleted_at'), so
                // this is never actually null — the `?? now()` is purely to
                // satisfy static analysis with a Carbon-typed fallback.
                $deletedAt = $row->deleted_at ?? now();
                $batches[$batch] ??= [
                    'batch' => $batch,
                    'deleted_at' => $deletedAt,
                    'locations' => 0,
                    'shelves' => 0,
                    'products' => 0,
                ];
                $batches[$batch][$key]++;
                // Rows in one batch share (for all practical purposes) one
                // deletion moment; take the latest seen so the summary always
                // reflects when the gesture actually happened even if clock
                // precision differs row to row.
                if ($deletedAt->gt($batches[$batch]['deleted_at'])) {
                    $batches[$batch]['deleted_at'] = $deletedAt;
                }
            }
        }

        return collect(array_values($batches))
            ->map(fn (array $b) => [
                'batch' => $b['batch'],
                'deleted_at' => $b['deleted_at'],
                'locations' => $b['locations'],
                'shelves' => $b['shelves'],
                'products' => $b['products'],
                'total' => $b['locations'] + $b['shelves'] + $b['products'],
            ])
            ->sortByDesc('deleted_at')
            ->values();
    }

    private static function cutoff(): ?Carbon
    {
        $days = (int) config('inventory.deleted_retention_days');

        // 0 disables pruning (retain forever) — see config/inventory.php.
        // No cutoff in that case: nothing is ever too old to list.
        return $days > 0 ? now()->subDays($days) : null;
    }
}
