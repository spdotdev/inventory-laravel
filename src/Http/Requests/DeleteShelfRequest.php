<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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
            // Client-minted: one user gesture may span several requests (deleting
            // three shelves), and only the client knows they were one gesture.
            'deletion_batch_id' => ['required', 'uuid'],
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

    public function batchId(): string
    {
        // Deliberately NOT `(string) $this->input(...)`: that would coerce a
        // missing value into '', and a stamp of '' collapses every deletion
        // across every household into one shared "batch" for Undo purposes.
        // Read from validated() (guaranteed a valid uuid by the rule above)
        // and fail loudly — not silently — if that contract is ever broken.
        $value = $this->validated('deletion_batch_id');

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException('deletion_batch_id was not validated as a required uuid.');
        }

        return $value;
    }

    public function targetShelfId(): ?int
    {
        $value = $this->input('target_shelf_id');

        return $value === null ? null : (int) $value;
    }
}
