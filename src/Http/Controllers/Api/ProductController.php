<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Http\Requests\ProductRequest;
use Spdotdev\Inventory\Http\Resources\ProductResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;

/**
 * Products on a shelf, plus the add/remove/move stock actions. Scoped binding
 * chains {product} ⊂ {shelf} ⊂ {household}; household.member guards membership.
 */
class ProductController
{
    public function index(Household $household, Shelf $shelf): AnonymousResourceCollection
    {
        return ProductResource::collection($shelf->products()->orderBy('name')->get());
    }

    public function store(ProductRequest $request, Household $household, Shelf $shelf): JsonResponse
    {
        $product = $shelf->products()->create($request->validated());

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Household $household, Shelf $shelf, Product $product): ProductResource
    {
        return new ProductResource($product);
    }

    public function update(ProductRequest $request, Household $household, Shelf $shelf, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product);
    }

    public function destroy(Household $household, Shelf $shelf, Product $product): JsonResponse
    {
        // Best-effort cleanup of the product's stored image so a direct delete
        // doesn't orphan the file (W15). NOTE: a *cascade* delete (removing the
        // shelf/location/household) is DB-level (ON DELETE CASCADE) and fires no
        // Eloquent event, so those images are intentionally left for the disk's
        // own lifecycle/GC — cleaning them would require app-level tree deletion,
        // which the hard-delete-cascade rule deliberately avoids.
        $image = $product->image_url;

        $product->delete();

        if ($image !== null) {
            $this->deleteStoredImage((string) config('inventory.image_disk', 'public'), $image);
        }

        return response()->json(['message' => 'Product deleted.']);
    }

    public function add(Request $request, Household $household, Shelf $shelf, Product $product): ProductResource
    {
        $product->addStock($this->amount($request), ProductRequest::MAX_QUANTITY);

        return new ProductResource($product);
    }

    public function remove(Request $request, Household $household, Shelf $shelf, Product $product): ProductResource
    {
        $product->removeStock($this->amount($request));

        return new ProductResource($product);
    }

    public function image(Request $request, Household $household, Shelf $shelf, Product $product): ProductResource
    {
        $disk = (string) config('inventory.image_disk', 'public');

        $request->validate([
            // mimetypes (server-detected) not the `image` rule, so tests don't
            // need GD to synthesize a real bitmap.
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.(int) config('inventory.image_max_kb', 5120)],
        ]);

        // Best-effort cleanup of a previously-stored image on the same disk.
        $previous = $product->image_url;

        $path = $request->file('image')->store('inventory/products', $disk);
        $url = Storage::disk($disk)->url($path);
        // Local/public disks return a root-relative path; make it absolute so the
        // client (which loads image_url directly) can fetch it. S3 etc. are already absolute.
        $product->update(['image_url' => str_starts_with($url, 'http') ? $url : url($url)]);

        if ($previous !== null) {
            $this->deleteStoredImage($disk, $previous);
        }

        return new ProductResource($product);
    }

    public function move(Request $request, Household $household, Shelf $shelf, Product $product): ProductResource
    {
        /** @var array{shelf_id: int} $data */
        $data = $request->validate(['shelf_id' => ['required', 'integer']]);

        // Target shelf must belong to the same household — otherwise a member
        // could move a product into another household's shelf.
        $target = $household->shelves()->whereKey($data['shelf_id'])->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'shelf_id' => ['The selected shelf is not in this household.'],
            ]);
        }

        $product->shelf_id = (int) $target->getKey();
        $product->save();

        return new ProductResource($product);
    }

    /**
     * Best-effort removal of a previously-stored product image so replacing a
     * photo doesn't orphan the old file. We only know the public URL, so we
     * recover the disk-relative path from the known `inventory/products/` prefix
     * and delete it if it still exists. Off-disk / externally-hosted URLs (no
     * matching prefix) are left untouched.
     */
    private function deleteStoredImage(string $disk, string $imageUrl): void
    {
        $marker = 'inventory/products/';
        $pos = strpos($imageUrl, $marker);

        if ($pos === false) {
            return;
        }

        $path = substr($imageUrl, $pos);

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    private function amount(Request $request): int
    {
        // Cap the single-request delta so a huge/typo'd amount is a clean 422
        // rather than pushing quantity past the unsignedInteger column ceiling and
        // triggering a MySQL "out of range" 500 (W14).
        /** @var array{amount: int} $data */
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:'.ProductRequest::MAX_QUANTITY]]);

        return $data['amount'];
    }
}
