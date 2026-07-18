<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spdotdev\Inventory\Http\Requests\ProductRequest;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Support\ProductImage;

/**
 * Web CRUD + stock actions for products (Phase 2 stage 2). Tenancy via the
 * shared middleware + scoped bindings; validation via the API's ProductRequest;
 * stock mutations via the same atomic Product::addStock/removeStock the API uses.
 */
class WebProductController extends Controller
{
    public function store(ProductRequest $request, Household $household, Shelf $shelf): RedirectResponse
    {
        $shelf->products()->create($request->validated() + ['quantity' => 0]);

        return $this->backToLocation($household, $shelf)->with('status', __('Product added.'));
    }

    public function edit(Household $household, Shelf $shelf, Product $product): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.product-edit', [
            'household' => $household,
            'shelf' => $shelf,
            'product' => $product,
            // Move targets (GAP-8 parity with the API's move endpoint): every
            // OTHER shelf in the household, labelled location — shelf.
            'moveTargets' => $household->shelves()->with('location')
                ->whereKeyNot($shelf->getKey())
                ->get()
                ->sortBy([['location.name', 'asc'], ['name', 'asc']])
                ->values(),
        ]);
    }

    /**
     * Web twin of Api\ProductController::move — member-level like the API
     * (filing a product on the right shelf is everyday stock work, not
     * restructuring). Redirects to the edit page under the NEW shelf: the old
     * URL's scoped binding would 404 the moment the move lands.
     */
    public function move(Request $request, Household $household, Shelf $shelf, Product $product): RedirectResponse
    {
        /** @var array{shelf_id: int} $data */
        $data = $request->validate(['shelf_id' => ['required', 'integer']]);

        // Same scoping rule as the API twin: the target must be the caller's
        // household's shelf — Rule::exists cannot see the household.
        $target = $household->shelves()->whereKey($data['shelf_id'])->first();

        if ($target === null) {
            return back()->withErrors(['shelf_id' => __('The selected shelf is not in this household.')]);
        }

        $product->shelf_id = (int) $target->getKey();
        $product->save();

        return redirect()
            ->route('inventory.web.products.edit', [$household, $target, $product])
            ->with('status', __('Product moved.'));
    }

    public function update(ProductRequest $request, Household $household, Shelf $shelf, Product $product): RedirectResponse
    {
        // Web checkboxes are absent when unchecked; normalize before the fill so
        // unchecking actually persists (the API sends explicit booleans instead).
        $data = $request->validated();
        $data['is_mandatory'] = $request->boolean('is_mandatory');
        $data['is_starred'] = $request->boolean('is_starred');
        $data['low_stock_threshold'] = $request->filled('low_stock_threshold')
            ? (int) $request->input('low_stock_threshold')
            : null;
        $product->update($data);

        return $this->backToLocation($household, $shelf)->with('status', __('Product saved.'));
    }

    public function image(Request $request, Household $household, Shelf $shelf, Product $product): RedirectResponse
    {
        // Mirrors Api\ProductController::image exactly (validation, disk,
        // image_url population, old-image cleanup via the shared ProductImage
        // helper) — web parity T5. Kept as a documented mirror rather than an
        // extracted class since the whole body is five lines.
        $disk = (string) config('inventory.image_disk', 'public');

        $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.(int) config('inventory.image_max_kb', 5120)],
        ]);

        $previous = $product->image_url;

        $path = $request->file('image')->store('inventory/products', $disk);
        $url = Storage::disk($disk)->url($path);
        $product->update(['image_url' => str_starts_with($url, 'http') ? $url : url($url)]);

        ProductImage::delete($disk, $previous);

        return redirect()->route('inventory.web.products.edit', [$household, $shelf, $product])
            ->with('status', __('Photo uploaded.'));
    }

    public function add(Household $household, Shelf $shelf, Product $product): RedirectResponse
    {
        $product->addStock(1, ProductRequest::MAX_QUANTITY);

        // redirect()->back() so the steppers work from both callers (location
        // page and the product edit page — audit #12); location is the fallback.
        return redirect()->back(fallback: route('inventory.web.locations.show', [$household, $shelf->location_id]));
    }

    public function remove(Household $household, Shelf $shelf, Product $product): RedirectResponse
    {
        $product->removeStock(1);

        return redirect()->back(fallback: route('inventory.web.locations.show', [$household, $shelf->location_id]));
    }

    public function destroy(Household $household, Shelf $shelf, Product $product): RedirectResponse
    {
        // Mint a batch-of-one server-side (the web UI has no client to supply
        // one) so a solo product delete is restorable via the same
        // batch-keyed restore surface as a shelf/location delete — see the
        // API's ProductController::destroy for the full reasoning. Image
        // cleanup on delete is still deliberately NOT done here — see there too.
        $batchId = (string) Str::uuid();
        $product->newQuery()->whereKey($product->getKey())->update(['deletion_batch_id' => $batchId]);
        $product->delete();

        // Web parity T4: see WebLocationController::destroy for the undo-flash
        // rationale. The Undo flash only renders for users who can actually
        // restore — WebRestoreController is restructure-gated, so showing a
        // Member an Undo button meant a bare 403 on click (audit #8).
        $redirect = $this->backToLocation($household, $shelf)->with('status', __('Product deleted.'));
        if (Gate::allows('restructure', $household)) {
            $redirect->with('undo', ['batch' => $batchId, 'household' => (int) $household->getKey()]);
        }

        return $redirect;
    }

    private function backToLocation(Household $household, Shelf $shelf): RedirectResponse
    {
        return redirect()->route('inventory.web.locations.show', [$household, $shelf->location_id]);
    }
}
