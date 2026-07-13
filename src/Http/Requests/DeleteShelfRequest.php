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
            'strategy' => [Rule::requiredIf(fn () => $this->shelfHasProducts()), Rule::enum(ShelfDeleteStrategy::class)],
            'target_shelf_id' => [
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
        return (string) $this->input('deletion_batch_id');
    }

    public function targetShelfId(): ?int
    {
        $value = $this->input('target_shelf_id');

        return $value === null ? null : (int) $value;
    }
}
