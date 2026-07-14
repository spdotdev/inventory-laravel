<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spdotdev\Inventory\Enums\ShelfDeleteStrategy;
use Spdotdev\Inventory\Models\Shelf;

/**
 * Deleting a shelf that still holds products REQUIRES an explicit strategy.
 * The client always knows the product count, so a missing strategy is a client
 * bug, not a user choice — 422 it rather than silently destroying stock.
 */
class DeleteShelfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 'nullable' lets the Android client OMIT-or-null these two fields
            // uniformly without a 422 on the (integer|enum) type rule below —
            // see DeleteLocationRequest for the full reasoning, one level up.
            // Rule::requiredIf is unaffected by 'nullable': it compiles to a
            // plain 'required' rule when its condition is true, which
            // Laravel always evaluates regardless of 'nullable' — so an
            // explicit null strategy on a shelf that DOES have products still
            // 422s. The server never guesses.
            'strategy' => ['nullable', Rule::requiredIf(fn () => $this->shelfHasProducts()), Rule::enum(ShelfDeleteStrategy::class)],
            'target_shelf_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('strategy') === ShelfDeleteStrategy::MoveProducts->value),
                'integer',
            ],
            // OPTIONAL, not required: a client-minted id is preferred (it is
            // what lets one user gesture spanning several requests — deleting
            // three shelves — share one batch, so Undo restores all three as a
            // unit), but the Android build already shipped to testers (v0.1.8)
            // sends a bodyless DELETE with no batch id at all. Rejecting that
            // outright would 422 every shelf delete on every phone already in
            // the field the moment this backend goes live — see batchId()
            // below for what happens when the client omits it. 'nullable'
            // also absorbs an explicit null for the same wire-format reason as
            // strategy/target_shelf_id above.
            'deletion_batch_id' => ['nullable', 'uuid'],
        ];
    }

    private function shelfHasProducts(): bool
    {
        $shelf = $this->route('shelf');

        return $shelf instanceof Shelf && $shelf->products()->exists();
    }

    public function strategy(): ?ShelfDeleteStrategy
    {
        $value = $this->input('strategy');

        return is_string($value) ? ShelfDeleteStrategy::from($value) : null;
    }

    // Memoises a server-minted id so repeated calls within the SAME request
    // (destroy() calls batchId() twice: once for HierarchyDeleter, once for
    // the response body) agree — a fresh uuid per call would split one
    // delete across two different "batches", and the response would then
    // advertise an id that was never actually stamped on the row.
    private ?string $mintedBatchId = null;

    public function batchId(): string
    {
        // A client-supplied id always wins — never overridden by a
        // server-minted one, or multi-item Undo (three shelf deletes sharing
        // one gesture) would silently split across batches.
        //
        // Deliberately NOT `(string) $this->input(...)`: that would coerce a
        // missing value into '', and a stamp of '' collapses every deletion
        // across every household into one shared "batch" for Undo purposes.
        // Read from validated() (guaranteed either absent/null or a valid
        // uuid by the rule above).
        $value = $this->validated('deletion_batch_id');

        if (is_string($value) && $value !== '') {
            return $value;
        }

        // Absent (or explicit null — same wire-format reason as strategy):
        // the shipped Android client (v0.1.8) never sends this field. Mint a
        // batch-of-one so the row still lands restorable — without it the
        // row would carry a NULL deletion_batch_id, and the batch-keyed
        // restore surface (POST .../restore/{batch}) has no id to reach it
        // by, making it permanently unrestorable. Mirrors
        // ProductController::destroy()'s identical fix for a solo product
        // delete, which has no shelf/location delete to ride along with either.
        return $this->mintedBatchId ??= (string) Str::uuid();
    }

    public function targetShelfId(): ?int
    {
        $value = $this->input('target_shelf_id');

        return $value === null ? null : (int) $value;
    }
}
