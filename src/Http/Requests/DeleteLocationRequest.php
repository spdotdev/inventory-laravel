<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spdotdev\Inventory\Enums\LocationDeleteStrategy;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Deleting a location that still holds contents REQUIRES an explicit strategy
 * — see StorageLocation::shelvesWithContents() for exactly what counts (a
 * non-system shelf of any kind, or a system Unsorted shelf that holds
 * products; an empty Unsorted shelf alone does not). See DeleteShelfRequest —
 * same reasoning, one level up.
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
            // 'nullable' lets the Android client OMIT-or-null these two fields
            // uniformly without a 422 on the (integer|enum) type rule below —
            // Kotlinx serialization with explicitNulls=true always encodes a
            // no-default property, even when it holds null. Rule::requiredIf
            // is unaffected: it compiles to a plain 'required' rule when its
            // condition is true, which Laravel's validator always evaluates
            // regardless of 'nullable' (see Validator::isNotNullIfMarkedAsNullable) —
            // so an explicit null strategy on a container that DOES have
            // contents still 422s. The server never guesses.
            'strategy' => ['nullable', Rule::requiredIf(fn () => $this->locationHasContents()), Rule::enum(LocationDeleteStrategy::class)],
            'target_location_id' => [
                'nullable',
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

        // Delegates to StorageLocation::shelvesWithContents() — see its
        // docblock. An empty system Unsorted shelf alone does NOT require a
        // strategy: it's disposable and invisible to the user, and
        // HierarchyDeleter::deleteLocation() leaves it live-but-orphaned under
        // the now-deleted location when no strategy runs, exactly like the
        // "harmless, disposable, reused/recreated on demand" empty Unsorted
        // shelf left behind by a failed delete (see that class's docblock) —
        // it is swept up for real by the retention purge's ON DELETE CASCADE
        // if the location is never restored. This method MUST stay in
        // lockstep with LocationResource's shelf_count: the Android client
        // decides whether to send a strategy from shelf_count > 0 alone, and
        // any divergence here would turn that into an unpredictable 422.
        return $location instanceof StorageLocation && $location->shelvesWithContents()->exists();
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
