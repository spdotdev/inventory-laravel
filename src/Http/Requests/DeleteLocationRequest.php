<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spdotdev\Inventory\Enums\LocationDeleteStrategy;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Deleting a location that still holds shelves REQUIRES an explicit strategy.
 * See DeleteShelfRequest — same reasoning, one level up.
 */
class DeleteLocationRequest extends FormRequest
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
            'strategy' => [Rule::requiredIf(fn () => $this->locationHasContents()), Rule::enum(LocationDeleteStrategy::class)],
            'target_location_id' => [
                Rule::requiredIf(fn () => $this->input('strategy') === LocationDeleteStrategy::MoveContents->value),
                'integer',
            ],
            // Client-minted: one user gesture may span several requests (deleting
            // several locations), and only the client knows they were one gesture.
            'deletion_batch_id' => ['required', 'uuid'],
        ];
    }

    private function locationHasContents(): bool
    {
        $location = $this->route('location');

        return $location instanceof StorageLocation && $location->shelves()->exists();
    }

    public function strategy(): ?LocationDeleteStrategy
    {
        $value = $this->input('strategy');

        return is_string($value) ? LocationDeleteStrategy::from($value) : null;
    }

    public function batchId(): string
    {
        // Deliberately NOT `(string) $this->input(...)`: that would coerce a
        // missing value into '', and a stamp of '' collapses every deletion
        // across every household into one shared "batch" for Undo purposes.
        // Read from validated() (guaranteed a valid uuid by the rule above)
        // and fail loudly — not silently — if that contract is ever broken.
        // See DeleteShelfRequest — same fix, same reasoning, one level up.
        $value = $this->validated('deletion_batch_id');

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException('deletion_batch_id was not validated as a required uuid.');
        }

        return $value;
    }

    public function targetLocationId(): ?int
    {
        $value = $this->input('target_location_id');

        return $value === null ? null : (int) $value;
    }
}
