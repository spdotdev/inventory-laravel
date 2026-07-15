<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for reclaiming a product's stored image file.
 *
 * A soft-deleted product deliberately keeps its image (the row is restorable,
 * so the photo must outlive it) — the retention purge
 * (`inventory:deleted:prune`) is the only place a product's file is ever
 * allowed to be deleted, alongside the replace-on-upload cleanup in
 * `ProductController::image()`. Both call sites need identical rules for
 * turning the stored `image_url` back into a disk-relative path, or they
 * drift: one used to fall back to treating an unmarked URL as a raw path and
 * deleting it outright, which is exactly how an arbitrary string could end up
 * passed to `Storage::delete()`.
 *
 * We only ever store the public URL, so recovering the disk path means
 * locating the known `inventory/products/` upload-path marker and taking
 * everything from there onward. A URL with no such marker was never written
 * by `ProductController::image()` — it is not a file this package manages, so
 * it is left alone rather than guessed at.
 */
class ProductImage
{
    private const MARKER = 'inventory/products/';

    /**
     * Best-effort delete of the file behind $imageUrl on $disk. No-op if the
     * URL doesn't carry the `inventory/products/` marker, or if the file
     * doesn't exist.
     */
    public static function delete(string $disk, ?string $imageUrl): void
    {
        if ($imageUrl === null) {
            return;
        }

        $pos = strpos($imageUrl, self::MARKER);

        if ($pos === false) {
            return;
        }

        $path = substr($imageUrl, $pos);

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
