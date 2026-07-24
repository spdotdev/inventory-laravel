<?php

namespace Spdotdev\Inventory\Observers;

use Illuminate\Database\Eloquent\Model;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Support\ActivityLog;

/**
 * Mirrors BroadcastHouseholdChange's registration (same four models, same
 * created/updated/deleted hooks, same household-id resolution) so every
 * Eloquent-level mutation gets both a live-update ping AND a permanent audit
 * row, with no controller having to remember either. Like that observer,
 * this one is silent for query-builder writes (HierarchyDeleter's cascades,
 * Restorer's restores, Product::addStock/removeStock, pivot writes) — those
 * call ActivityLog::record() directly; see the design spec's Capture
 * mechanism section.
 */
class RecordActivityLog
{
    public function created(Model $model): void
    {
        $this->log($model, 'created', null);
    }

    public function updated(Model $model): void
    {
        $dirty = $model->getChanges();
        unset($dirty['updated_at']);

        if ($dirty === []) {
            return;
        }

        $changes = [];
        foreach ($dirty as $field => $to) {
            $changes[$field] = ['from' => $model->getOriginal($field), 'to' => $to];
        }

        $this->log($model, 'updated', $changes);
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', null);
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>|null  $changes
     */
    private function log(Model $model, string $verb, ?array $changes): void
    {
        $householdId = $this->householdId($model);

        if ($householdId === null) {
            return;
        }

        $subjectType = class_basename($model);
        $action = strtolower($subjectType).'.'.$verb;
        $label = (string) ($model->name ?? $model->getKey());

        ActivityLog::record(
            $householdId,
            auth()->id(),
            $action,
            $subjectType,
            $model->exists ? (int) $model->getKey() : null,
            $label,
            $changes,
        );
    }

    private function householdId(Model $model): ?int
    {
        return match (true) {
            $model instanceof Household => $model->exists ? (int) $model->getKey() : null,
            $model instanceof StorageLocation => (int) $model->household_id,
            $model instanceof Shelf => $model->location?->household_id !== null
                ? (int) $model->location->household_id
                : null,
            $model instanceof Product => $model->householdId(),
            default => null,
        };
    }
}
