<?php

namespace Spdotdev\Inventory\Observers;

use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\ProductImage;

/**
 * A Household is the one row in this package that is still ever HARD deleted
 * (see HouseholdController::leave() when the last member goes, and
 * AdminController::deleteHousehold() / the MCP delete_household tool). Its
 * `ON DELETE CASCADE` FKs take every location, shelf and product with it —
 * but a cascade is a plain SQL DELETE against those tables. It drops the
 * ROWS; it has no idea a product's `image_url` points at a file on disk, so
 * it leaves that file behind forever.
 *
 * All three hard-delete call sites funnel through Household::delete()
 * directly (verified — the MCP tool does NOT call the admin HTTP endpoint,
 * it deletes the model itself), so a `deleting` observer here is the one
 * choke point that covers all of them without copy-pasting this walk into
 * each controller. It fires BEFORE the row (and therefore the cascade) is
 * gone, while the tree can still be queried to find every image.
 *
 * Includes already soft-deleted locations/shelves/products: a soft-deleted
 * product still has its file on disk (soft delete deliberately keeps the
 * image so a later restore isn't left pointing at a missing photo — see
 * ProductController::destroy) and the cascade destroys those rows exactly as
 * surely as live ones the moment the household goes.
 *
 * Deleting the actual file is ProductImage::delete()'s job — the one place
 * that logic lives (see its docblock); this class only finds which products
 * to hand it.
 */
class ReclaimHouseholdProductImages
{
    public function deleting(Household $household): void
    {
        $locationIds = StorageLocation::withTrashed()
            ->where('household_id', $household->getKey())
            ->pluck('id');

        if ($locationIds->isEmpty()) {
            return;
        }

        $shelfIds = Shelf::withTrashed()
            ->whereIn('location_id', $locationIds)
            ->pluck('id');

        if ($shelfIds->isEmpty()) {
            return;
        }

        $disk = (string) config('inventory.image_disk', 'public');

        Product::withTrashed()
            ->whereIn('shelf_id', $shelfIds)
            ->whereNotNull('image_url')
            ->get()
            ->each(fn (Product $product) => ProductImage::delete($disk, $product->image_url));
    }
}
