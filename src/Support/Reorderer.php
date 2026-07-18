<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Shared all-or-nothing reorder writer for locations and shelves — used by
 * both the API (Http\Controllers\Api\LocationController/ShelfController) and
 * the web parity twins (Http\Controllers\Web\WebLocationController/
 * WebShelfController) so the validation + transaction semantics can never
 * drift between the two surfaces. Only authorization (`Gate::authorize`,
 * caller-specific) and the response shape stay in the controllers, matching
 * how HierarchyDeleter is shared for delete strategies.
 */
class Reorderer
{
    /**
     * @param  list<int>  $ids
     *
     * @throws ValidationException when $ids is not exactly the complete,
     *                             deduplicated set of this household's live
     *                             locations (partial and foreign lists both
     *                             rejected — see the API controller's
     *                             docblock for why a partial write is worse
     *                             than a rejected one).
     */
    public static function locations(Household $household, array $ids): void
    {
        $owned = $household->locations()->whereKey($ids)->pluck('id')->all();
        $total = $household->locations()->count();

        if (count($owned) !== count($ids) || $total !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => [__('The list must contain every location in this household, and only those.')],
            ]);
        }

        DB::transaction(function () use ($ids, $household) {
            foreach ($ids as $position => $id) {
                $household->locations()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /**
     * @param  list<int>  $ids
     *
     * @throws ValidationException when $ids is not exactly the complete,
     *                             deduplicated set of this location's live,
     *                             non-system shelves. The Unsorted (system)
     *                             shelf is never draggable and always sorts
     *                             last via `is_system` — it must be absent
     *                             from $ids, and its absence must not count
     *                             against completeness.
     */
    public static function shelves(StorageLocation $location, array $ids): void
    {
        $owned = $location->shelves()->where('is_system', false)->whereKey($ids)->pluck('id')->all();
        $total = $location->shelves()->where('is_system', false)->count();

        if (count($owned) !== count($ids) || $total !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => [__('The list must contain every shelf in this location, and only those.')],
            ]);
        }

        DB::transaction(function () use ($ids, $location) {
            foreach ($ids as $position => $id) {
                $location->shelves()->whereKey($id)->update(['position' => $position]);
            }
        });
    }
}
