<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\ShelfDeleteStrategy;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Support\HierarchyDeleter;
use Spdotdev\Inventory\Support\Restorer;
use Spdotdev\Inventory\Tests\TestCase;

class ActivityLogManualCaptureTest extends TestCase
{
    use RefreshDatabase;

    private static int $userSeq = 0;

    private function makeUser(): User
    {
        $n = ++self::$userSeq;

        return User::create(['name' => "User{$n}", 'email' => "user{$n}@example.test", 'password' => 'secret-password']);
    }

    private function ownedHousehold(): array
    {
        $owner = $this->makeUser();
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        return [$household, $owner];
    }

    public function test_changing_a_member_role_logs_member_role_changed(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $member = $this->makeUser();
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($owner)
            ->patchJson("http://inventory.test/api/v1/households/{$household->getKey()}/members/{$member->getKey()}", ['role' => 'admin'])
            ->assertOk();

        $entry = ActivityLogEntry::where('action', 'member.role_changed')->firstOrFail();
        $this->assertSame((int) $owner->getKey(), $entry->actor_id);
        $this->assertSame(['from' => 'member', 'to' => 'admin'], $entry->changes['role']);
    }

    public function test_removing_a_member_logs_member_removed(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $member = $this->makeUser();
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($owner)
            ->deleteJson("http://inventory.test/api/v1/households/{$household->getKey()}/members/{$member->getKey()}")
            ->assertOk();

        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'member.removed']);
    }

    public function test_joining_a_household_logs_member_added(): void
    {
        [$household, ] = $this->ownedHousehold();
        $joiner = $this->makeUser();

        $this->actingAs($joiner)
            ->postJson('http://inventory.test/api/v1/households/join', ['code' => $household->join_code])
            ->assertOk();

        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'member.added', 'actor_id' => $joiner->getKey()]);
    }

    public function test_rejoining_a_household_does_not_log_a_second_member_added(): void
    {
        [$household, ] = $this->ownedHousehold();
        $joiner = $this->makeUser();

        $this->actingAs($joiner)
            ->postJson('http://inventory.test/api/v1/households/join', ['code' => $household->join_code])
            ->assertOk();

        $this->actingAs($joiner)
            ->postJson('http://inventory.test/api/v1/households/join', ['code' => $household->join_code])
            ->assertOk();

        $this->assertSame(
            1,
            ActivityLogEntry::where('action', 'member.added')->where('actor_id', $joiner->getKey())->count(),
        );
    }

    public function test_transferring_ownership_logs_household_ownership_transferred(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $member = $this->makeUser();
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($owner)
            ->postJson("http://inventory.test/api/v1/households/{$household->getKey()}/transfer-ownership", ['user_id' => $member->getKey()])
            ->assertOk();

        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'household.ownership_transferred']);
    }

    public function test_add_stock_logs_product_stock_added(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => StorageType::Pantry]);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);
        $product = Product::create(['shelf_id' => $shelf->getKey(), 'name' => 'Milk', 'quantity' => 0]);

        $this->actingAs($owner);
        $product->addStock(2, 10);

        $entry = ActivityLogEntry::where('action', 'product.stock_added')->firstOrFail();
        $this->assertSame(['from' => 0, 'to' => 2], $entry->changes['quantity']);
    }

    public function test_cascading_shelf_delete_logs_one_summarized_batch_entry(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => StorageType::Pantry]);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);
        Product::create(['shelf_id' => $shelf->getKey(), 'name' => 'Milk', 'quantity' => 1]);
        Product::create(['shelf_id' => $shelf->getKey(), 'name' => 'Eggs', 'quantity' => 1]);

        HierarchyDeleter::deleteShelf($household, $shelf, (string) \Illuminate\Support\Str::uuid(), ShelfDeleteStrategy::DeleteProducts, null, (int) $owner->getKey());

        $entry = ActivityLogEntry::where('action', 'shelf.deleted_batch')->firstOrFail();
        $this->assertSame('Pantry', $entry->subject_label);
        $this->assertSame(2, $entry->changes['cascaded']['products']);
    }

    public function test_restoring_a_batch_logs_a_restored_batch_entry(): void
    {
        [$household, $owner] = $this->ownedHousehold();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => StorageType::Pantry]);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);
        $batch = (string) \Illuminate\Support\Str::uuid();
        HierarchyDeleter::deleteShelf($household, $shelf, $batch, ShelfDeleteStrategy::DeleteProducts, null, (int) $owner->getKey());

        Restorer::restore($household, $batch);

        $this->assertDatabaseHas('inventory_activity_log', ['action' => 'household.restored_batch']);
    }
}
