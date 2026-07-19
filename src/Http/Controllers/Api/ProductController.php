<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Http\Requests\ProductRequest;
use Spdotdev\Inventory\Http\Resources\ProductResource;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Support\ProductImage;

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

    public function destroy(Request $request, Household $household, Shelf $shelf, Product $product): JsonResponse
    {
        // A solo product delete has no shelf/location delete to ride along
        // with, so mint a batch-of-one here: without it the row lands with a
        // NULL deletion_batch_id, and the batch-keyed restore surface
        // (POST .../restore/{batch}) has no id to reach it by — permanently
        // unrestorable, unlike a product caught up in a shelf/location delete.
        // Stamped via the query builder (no model event) so the delete()
        // below still fires exactly one `deleted` event/broadcast. deleted_by
        // records who ran this gesture so a Member (who has no restructure
        // grant) can still restore this specific batch themselves — see
        // HouseholdPolicy::restoreBatch.
        $batchId = (string) Str::uuid();
        $product->newQuery()->whereKey($product->getKey())->update([
            'deletion_batch_id' => $batchId,
            'deleted_by' => (int) $request->user()->getKey(),
        ]);

        // $product->delete() is a SOFT delete (an UPDATE) — the row survives so
        // Undo can restore it. The image file must survive with it: the row's
        // image_url still points at it, so deleting the file here would leave a
        // restored product with a dead photo. Image cleanup is the eventual hard
        // purge's job (inventory:deleted:prune) — see that command when it lands.
        $product->delete();

        return response()->json([
            'message' => 'Product deleted.',
            'deletion_batch_id' => $batchId,
        ]);
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

        ProductImage::delete($disk, $previous);

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
