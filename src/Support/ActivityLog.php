<?php

namespace Spdotdev\Inventory\Support;

use Spdotdev\Inventory\Models\ActivityLogEntry;

/**
 * Single write path into inventory_activity_log — both the automatic
 * RecordActivityLog observer and the manual call sites that bypass Eloquent
 * events (pivot writes, HierarchyDeleter/Restorer's query-builder writes,
 * Product::addStock/removeStock) go through this, so the row shape can only
 * drift in one place.
 */
class ActivityLog
{
    /**
     * @param  array<string, array{from: mixed, to: mixed}>|array{cascaded: array<string, int>}|null  $changes
     */
    public static function record(
        int $householdId,
        ?int $actorId,
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $subjectLabel,
        ?array $changes = null,
    ): void {
        ActivityLogEntry::create([
            'household_id' => $householdId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'changes' => $changes,
        ]);
    }
}
