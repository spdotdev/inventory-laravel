<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\AppNotification;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class PruneNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_rows_older_than_30_days_and_keeps_newer(): void
    {
        $user = User::query()->create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => bcrypt('secret123')]);
        $old = AppNotification::query()->create(['user_id' => $user->id, 'type' => 'activity', 'payload' => ['count' => 1]]);
        $old->newQuery()->whereKey($old->id)->update(['created_at' => now()->subDays(31)]);
        $fresh = AppNotification::query()->create(['user_id' => $user->id, 'type' => 'activity', 'payload' => ['count' => 2]]);

        $this->artisan('inventory:notifications:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('inventory_notifications', ['id' => $old->id]);
        $this->assertDatabaseHas('inventory_notifications', ['id' => $fresh->id]);
    }
}
