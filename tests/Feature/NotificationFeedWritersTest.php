<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\AppNotification;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class NotificationFeedWritersTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function user(string $name): User
    {
        return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => bcrypt('secret123')]);
    }

    public function test_join_notifies_owner_and_admin_but_not_joiner_or_member(): void
    {
        $owner = $this->user('Owner');
        $admin = $this->user('Admin');
        $member = $this->user('Member');
        $joiner = $this->user('Joiner');
        $h = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $h->users()->attach($owner, ['role' => 'owner']);
        $h->users()->attach($admin, ['role' => 'admin']);
        $h->users()->attach($member, ['role' => 'member']);

        Sanctum::actingAs($joiner);
        $this->postJson("{$this->base}/households/join", ['code' => 'AAAA1111'])->assertOk();

        $this->assertDatabaseHas('inventory_notifications', ['user_id' => $owner->id, 'household_id' => $h->id, 'type' => 'member_joined']);
        $this->assertDatabaseHas('inventory_notifications', ['user_id' => $admin->id, 'type' => 'member_joined']);
        $this->assertDatabaseMissing('inventory_notifications', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('inventory_notifications', ['user_id' => $joiner->id]);
    }

    public function test_rejoin_is_idempotent_and_writes_nothing(): void
    {
        $owner = $this->user('Owner');
        $joiner = $this->user('Joiner');
        $h = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $h->users()->attach($owner, ['role' => 'owner']);

        Sanctum::actingAs($joiner);
        $this->postJson("{$this->base}/households/join", ['code' => 'AAAA1111'])->assertOk();
        $this->postJson("{$this->base}/households/join", ['code' => 'AAAA1111'])->assertOk();

        $this->assertSame(1, AppNotification::query()
            ->where('user_id', $owner->id)
            ->where('type', 'member_joined')
            ->count());
    }

    public function test_role_change_notifies_affected_user_only(): void
    {
        $owner = $this->user('Owner');
        $member = $this->user('Member');
        $h = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $h->users()->attach($owner, ['role' => 'owner']);
        $h->users()->attach($member, ['role' => 'member']);

        Sanctum::actingAs($owner);
        $this->patchJson("{$this->base}/households/{$h->id}/members/{$member->id}", ['role' => 'admin'])->assertOk();

        $this->assertDatabaseHas('inventory_notifications', ['user_id' => $member->id, 'household_id' => $h->id, 'type' => 'role_changed']);
        $this->assertDatabaseMissing('inventory_notifications', ['user_id' => $owner->id]);
    }

    public function test_ownership_transfer_notifies_new_owner(): void
    {
        $owner = $this->user('Owner');
        $member = $this->user('Member');
        $h = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $h->users()->attach($owner, ['role' => 'owner']);
        $h->users()->attach($member, ['role' => 'member']);

        Sanctum::actingAs($owner);
        $this->postJson("{$this->base}/households/{$h->id}/transfer-ownership", ['user_id' => $member->id])->assertOk();

        $row = AppNotification::query()
            ->where('user_id', $member->id)
            ->where('type', 'role_changed')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('owner', $row->payload['role']['to']);
    }

    public function test_member_removed_writes_activity_for_remaining_members_not_actor_or_removed(): void
    {
        $owner = $this->user('Owner');
        $member = $this->user('Member');
        $other = $this->user('Other');
        $h = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $h->users()->attach($owner, ['role' => 'owner']);
        $h->users()->attach($member, ['role' => 'member']);
        $h->users()->attach($other, ['role' => 'member']);

        Sanctum::actingAs($owner);
        $this->deleteJson("{$this->base}/households/{$h->id}/members/{$member->id}")->assertOk();

        $this->assertDatabaseHas('inventory_notifications', ['user_id' => $other->id, 'household_id' => $h->id, 'type' => 'activity']);
        $this->assertDatabaseMissing('inventory_notifications', ['user_id' => $owner->id]);
        $this->assertDatabaseMissing('inventory_notifications', ['user_id' => $member->id]);
    }

    public function test_leave_writes_activity_for_remaining_members(): void
    {
        $owner = $this->user('Owner');
        $member = $this->user('Member');
        $other = $this->user('Other');
        $h = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $h->users()->attach($owner, ['role' => 'owner']);
        $h->users()->attach($member, ['role' => 'member']);
        $h->users()->attach($other, ['role' => 'member']);

        Sanctum::actingAs($member);
        $this->deleteJson("{$this->base}/households/{$h->id}/leave")->assertOk();

        $this->assertDatabaseHas('inventory_notifications', ['user_id' => $owner->id, 'household_id' => $h->id, 'type' => 'activity']);
        $this->assertDatabaseHas('inventory_notifications', ['user_id' => $other->id, 'type' => 'activity']);
        $this->assertDatabaseMissing('inventory_notifications', ['user_id' => $member->id]);
    }

    public function test_shelf_batch_delete_writes_one_activity_row_per_other_member(): void
    {
        $owner = $this->user('Owner');
        $other = $this->user('Other');
        $h = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $h->users()->attach($owner, ['role' => 'owner']);
        $h->users()->attach($other, ['role' => 'member']);

        $location = StorageLocation::query()->create(['household_id' => $h->id, 'name' => 'Fridge', 'type' => 'fridge']);
        $shelf = Shelf::query()->create(['location_id' => $location->id, 'name' => 'Top', 'position' => 0]);
        Product::query()->create(['shelf_id' => $shelf->id, 'name' => 'Milk']);
        Product::query()->create(['shelf_id' => $shelf->id, 'name' => 'Eggs']);

        Sanctum::actingAs($owner);
        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
        ])->assertOk();

        $rows = AppNotification::query()
            ->where('user_id', $other->id)
            ->where('type', 'activity')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('items_deleted', $rows->first()->payload['kind']);
        $this->assertGreaterThanOrEqual(1, $rows->first()->payload['count']);
        $this->assertDatabaseMissing('inventory_notifications', ['user_id' => $owner->id]);
    }
}
