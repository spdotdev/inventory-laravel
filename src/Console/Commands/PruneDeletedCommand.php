<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

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

        foreach ($doomed as $product) {
            $this->deleteStoredImage($product);
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

    /**
     * Same path-recovery technique as ProductController::deleteStoredImage
     * (best-effort cleanup on the configured disk, guarded by an existence
     * check) — reused here rather than reinvented, since this purge is the
     * only place a product's image is ever allowed to be deleted. Unlike that
     * call site, image_url here isn't guaranteed to carry the
     * `inventory/products/` upload-path marker (e.g. a directly-set path), so
     * an unmatched string falls back to being used as the disk-relative path
     * itself instead of being silently skipped.
     */
    private function deleteStoredImage(Product $product): void
    {
        $disk = (string) config('inventory.image_disk', 'public');
        $imageUrl = (string) $product->image_url;

        $marker = 'inventory/products/';
        $pos = strpos($imageUrl, $marker);
        $path = $pos !== false ? substr($imageUrl, $pos) : $imageUrl;

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
