<?php

namespace Spdotdev\Inventory\Support;

use Spdotdev\Inventory\Models\AppNotification;
use Spdotdev\Inventory\Models\Household;

/**
 * Single write path into inventory_notifications (mirrors ActivityLog's
 * design). Rows are user-addressed and coarse — never a full audit trail;
 * the MCP-only activity log keeps that role.
 */
class NotificationFeed
{
    /** @param array<string, mixed> $payload */
    public static function toUser(int $userId, ?int $householdId, string $type, array $payload): void
    {
        AppNotification::query()->create([
            'user_id' => $userId,
            'household_id' => $householdId,
            'type' => $type,
            'payload' => $payload,
        ]);
    }

    /** Owners and admins of the household, except $exceptUserId. */
    public static function toManagers(Household $household, int $exceptUserId, string $type, array $payload): void
    {
        $ids = $household->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->whereKeyNot($exceptUserId)
            ->pluck('inventory_users.id');
        foreach ($ids as $id) {
            self::toUser((int) $id, (int) $household->getKey(), $type, $payload);
        }
    }

    /** Every current member except the actor. */
    public static function toOtherMembers(Household $household, ?int $actorId, string $type, array $payload): void
    {
        $query = $household->users();
        if ($actorId !== null) {
            $query = $query->whereKeyNot($actorId);
        }
        foreach ($query->pluck('inventory_users.id') as $id) {
            self::toUser((int) $id, (int) $household->getKey(), $type, $payload);
        }
    }
}
