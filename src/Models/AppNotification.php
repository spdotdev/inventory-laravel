<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A per-user notification feed row (delivered today by Android's polling
 * worker; the schema is deliberately FCM-ready). Not the activity log:
 * inventory_activity_log stays MCP-only; this table holds coarse,
 * user-addressed rows only.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $household_id
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
class AppNotification extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'inventory_notifications';

    protected $fillable = ['user_id', 'household_id', 'type', 'payload', 'read_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'read_at' => 'datetime'];
    }
}
