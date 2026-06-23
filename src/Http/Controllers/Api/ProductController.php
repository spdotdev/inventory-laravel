<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    public function add(Request $request, Household $household, Shelf $shelf, Product $product): ProductResource
    {
        $product->increment('quantity', $this->amount($request));

        return new ProductResource($product->refresh());
    }

    public function remove(Request $request, Household $household, Shelf $shelf, Product $product): ProductResource
    {
        // Quantity floors at 0 (D-012); the row is retained as out-of-stock.
        $product->quantity = max(0, $product->quantity - $this->amount($request));
        $product->save();

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
        /** @var array{amount: int} $data */
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        return $data['amount'];
    }
}
