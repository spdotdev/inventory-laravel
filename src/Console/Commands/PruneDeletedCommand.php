<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\ProductImage;

class PruneDeletedCommand extends Command
{
    protected $signature = 'inventory:deleted:prune';

    protected $description = 'Hard delete soft-deleted locations, shelves and products past the retention window.';

    public function handle(): int
    {
        $days = (int) config('inventory.deleted_retention_days');

        if ($days <= 0) {
            $this->info('Deleted-row pruning is disabled (retention = 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        // Delete the stored images FIRST, while the rows still exist to tell us
        // where they are. Soft delete deliberately leaves the file alone — the row
        // is restorable, so the image has to outlive it — which makes the purge
        // the only place the file can ever be reclaimed. Skip this and every image
        // ever uploaded leaks on disk forever.
        $doomed = Product::onlyTrashed()->where('deleted_at', '<', $cutoff)->whereNotNull('image_url')->get();
        $disk = (string) config('inventory.image_disk', 'public');

        foreach ($doomed as $product) {
            ProductImage::delete($disk, $product->image_url);
        }

        // Children first. A location's forceDelete would ON DELETE CASCADE its
        // subtree anyway, but a shelf soft-deleted on its own (parent still
        // alive) has no cascade to ride — so each level is pruned explicitly.
        $products = Product::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();
        $shelves = Shelf::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();
        $locations = StorageLocation::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();

        $this->info("Pruned {$locations} location(s), {$shelves} shelf/shelves, {$products} product(s) deleted more than {$days} day(s) ago.");

        return self::SUCCESS;
    }
}
