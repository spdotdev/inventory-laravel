<?php

namespace Spdotdev\Inventory\Observers;

use Illuminate\Database\Eloquent\Model;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;

/**
 * Fires HouseholdChanged for every mutation of the household tree, at the
 * MODEL layer so every surface (API, web UI, artisan CLI, MCP tools) emits
 * the same signal without each controller remembering to. DB-level cascade
 * deletes don't fire Eloquent events for the children — fine, because the
 * parent's own deleted event already pings the household.
 */
class BroadcastHouseholdChange
{
    public function created(Model $model): void
    {
        $this->ping($model);
    }

    public function updated(Model $model): void
    {
        $this->ping($model);
    }

    public function deleted(Model $model): void
    {
        $this->ping($model);
    }

    private function ping(Model $model): void
    {
        $householdId = $this->householdId($model);
        if ($householdId !== null) {
            HouseholdChanged::dispatch($householdId);
        }
    }

    private function householdId(Model $model): ?int
    {
        return match (true) {
            $model instanceof Household => $model->exists ? (int) $model->getKey() : null,
            $model instanceof StorageLocation => (int) $model->household_id,
            $model instanceof Shelf => $model->location?->household_id !== null
                ? (int) $model->location->household_id
                : null,
            // Product::householdId() walks the same shelf -> location chain;
            // shared here so addStock()/removeStock() (which bypass Eloquent
            // events and dispatch this same ping themselves) don't carry a
            // second copy of the walk.
            $model instanceof Product => $model->householdId(),
            default => null,
        };
    }
}
