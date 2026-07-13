<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class UnsortedShelfTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    /** @return array{Household, StorageLocation} */
    private function memberLocation(): array
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        return [$household, $location];
    }

    public function test_unsorted_shelf_is_created_lazily_and_reused(): void
    {
        [, $location] = $this->memberLocation();

        $first = $location->unsortedShelf();
        $second = $location->unsortedShelf();

        $this->assertTrue($first->is_system);
        $this->assertSame('Unsorted', $first->name);
        $this->assertSame($first->id, $second->id, 'unsortedShelf() must find, not duplicate');
        $this->assertSame(1, $location->shelves()->where('is_system', true)->count());
    }

    public function test_unsorted_shelf_sorts_last(): void
    {
        [$h, $location] = $this->memberLocation();
        $unsorted = $location->unsortedShelf();
        $top = $location->shelves()->create(['name' => 'Top', 'position' => 0]);

        // is_system sorts after position, so Unsorted stays at the bottom no
        // matter what positions the real shelves hold.
        $this->getJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves")
            ->assertOk()
            ->assertJsonPath('data.0.id', $top->id)
            ->assertJsonPath('data.1.id', $unsorted->id)
            ->assertJsonPath('data.1.is_system', true);
    }

    public function test_the_unsorted_shelf_cannot_be_renamed(): void
    {
        [$h, $location] = $this->memberLocation();
        $unsorted = $location->unsortedShelf();

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$unsorted->id}", [
            'name' => 'Hijacked',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('inventory_shelves', ['id' => $unsorted->id, 'name' => 'Unsorted']);
    }

    public function test_the_unsorted_shelf_cannot_be_deleted_while_occupied(): void
    {
        [$h, $location] = $this->memberLocation();
        $unsorted = $location->unsortedShelf();
        $unsorted->products()->create(['name' => 'Orphan peas', 'quantity' => 1]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$unsorted->id}")
            ->assertStatus(422);

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $unsorted->id]);
    }

    public function test_an_empty_unsorted_shelf_can_be_deleted(): void
    {
        [$h, $location] = $this->memberLocation();
        $unsorted = $location->unsortedShelf();

        // Nothing precious about it once empty — it is recreated on demand.
        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$unsorted->id}")
            ->assertOk();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $unsorted->id]);
    }
}
