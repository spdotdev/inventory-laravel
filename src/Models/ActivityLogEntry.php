<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit-trail row. Never updated after creation — see the
 * household-activity-log design spec for why (MCP-only feature, kept
 * forever, no prune job).
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string $subject_label
 * @property array<string, array{from: mixed, to: mixed}>|array{cascaded: array<string, int>}|null $changes
 */
class ActivityLogEntry extends Model
{
    protected $table = 'inventory_activity_log';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'changes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }
}
