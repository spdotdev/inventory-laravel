<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class RecordActivityLogObserverTest extends TestCase
{
    use RefreshDatabase;

    private function household(): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);
        $this->actingAs($user);

        return $household;
    }

    public function test_creating_a_household_logs_household_created(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $this->actingAs($user);

        $household = Household::create(['name' => 'Casa', 'join_code' => 'XYZ789']);

        $entry = ActivityLogEntry::where('household_id', $household->getKey())->firstOrFail();
        $this->assertSame('household.created', $entry->action);
        $this->assertSame((int) $user->getKey(), $entry->actor_id);
        $this->assertSame('Casa', $entry->subject_label);
        $this->assertNull($entry->changes);
    }

    public function test_updating_a_product_logs_the_field_diff(): void
    {
        $household = $this->household();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => StorageType::Pantry]);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);
        $product = Product::create(['shelf_id' => $shelf->getKey(), 'name' => 'Milk', 'quantity' => 3]);

        $product->update(['quantity' => 0]);

        $entry = ActivityLogEntry::where('action', 'product.updated')->firstOrFail();
        $this->assertSame('Milk', $entry->subject_label);
        // assertEquals, not assertSame: MySQL's JSON column type doesn't
        // preserve object key insertion order the way SQLite does, so the
        // decoded array's key order isn't guaranteed - only the values matter.
        $this->assertEquals(['from' => 3, 'to' => 0], $entry->changes['quantity']);
    }

    public function test_deleting_a_shelf_via_eloquent_logs_shelf_deleted(): void
    {
        $household = $this->household();
        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => StorageType::Pantry]);
        $shelf = Shelf::create(['location_id' => $location->getKey(), 'name' => 'Pantry']);

        $shelf->delete();

        $entry = ActivityLogEntry::where('action', 'shelf.deleted')->firstOrFail();
        $this->assertSame('Pantry', $entry->subject_label);
    }

    public function test_deleting_a_household_logs_household_deleted(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $this->actingAs($user);

        $household = Household::create(['name' => 'Casa', 'join_code' => 'DEL123']);
        $householdId = $household->getKey();

        $household->delete();

        $entry = ActivityLogEntry::where('household_id', $householdId)
            ->where('action', 'household.deleted')
            ->firstOrFail();
        $this->assertSame('Casa', $entry->subject_label);
    }

    public function test_storage_location_events_use_location_action_prefix(): void
    {
        $household = $this->household();

        $location = StorageLocation::create(['household_id' => $household->getKey(), 'name' => 'Kitchen', 'type' => StorageType::Pantry]);
        $this->assertTrue(
            ActivityLogEntry::where('action', 'location.created')->where('subject_label', 'Kitchen')->exists()
        );

        $location->update(['name' => 'Garage']);
        $this->assertTrue(
            ActivityLogEntry::where('action', 'location.updated')->exists()
        );

        $location->delete();
        $this->assertTrue(
            ActivityLogEntry::where('action', 'location.deleted')->exists()
        );

        $this->assertFalse(
            ActivityLogEntry::where('action', 'like', 'storagelocation.%')->exists()
        );
    }
}
