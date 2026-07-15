<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
            // OPTIONAL, not required: a client-minted id is preferred (it is
            // what lets one user gesture spanning several requests — deleting
            // several locations — share one batch, so Undo restores all of
            // them as a unit), but the Android build already shipped to
            // testers (v0.1.8) sends a bodyless DELETE with no batch id at
            // all. Rejecting that outright would 422 every location delete on
            // every phone already in the field the moment this backend goes
            // live — see batchId() below for what happens when the client
            // omits it. 'nullable' also absorbs an explicit null for the same
            // wire-format reason as strategy/target_location_id above.
            'deletion_batch_id' => ['nullable', 'uuid'],
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

    // Memoises a server-minted id so repeated calls within the SAME request
    // (destroy() calls batchId() twice: once for HierarchyDeleter, once for
    // the response body) agree — a fresh uuid per call would split one
    // delete across two different "batches", and the response would then
    // advertise an id that was never actually stamped on the row.
    private ?string $mintedBatchId = null;

    public function batchId(): string
    {
        // A client-supplied id always wins — never overridden by a
        // server-minted one, or multi-item Undo (several location deletes
        // sharing one gesture) would silently split across batches.
        //
        // Deliberately NOT `(string) $this->input(...)`: that would coerce a
        // missing value into '', and a stamp of '' collapses every deletion
        // across every household into one shared "batch" for Undo purposes.
        // Read from validated() (guaranteed either absent/null or a valid
        // uuid by the rule above). See DeleteShelfRequest — same fix, same
        // reasoning, one level up.
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

    public function targetLocationId(): ?int
    {
        $value = $this->input('target_location_id');

        return $value === null ? null : (int) $value;
    }
}
