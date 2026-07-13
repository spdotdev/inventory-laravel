<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class UnsortedShelfTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    // Returns [Household, the StorageLocation created within it]. No
    // StorageLocation import here: `tests/` isn't in phpstan.neon's analysed
    // paths, so an @return array-shape docblock buys no static-analysis value
    // in this file — only Pint's fully_qualified_strict_types fixer would
    // insist on the import back in if the type were named in a PHPDoc tag.
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
        // Position 5 — NOT 0 — is deliberate: the Unsorted shelf is also at
        // position 0 (irrelevant per its own doc comment), so if this real
        // shelf shared that position the assertion below would only pass
        // because of the DB's rowid tie-break, not because of is_system
        // ordering. A distinct, higher position makes the assertion fail
        // unless orderBy('is_system') is actually doing the work.
        $top = $location->shelves()->create(['name' => 'Top', 'position' => 5]);

        // is_system is the PRIMARY sort key (false < true), so Unsorted lands
        // after every real shelf no matter what position those shelves hold —
        // position is only the tie-break within each is_system group.
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

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$unsorted->id}", [
            'deletion_batch_id' => '11111111-1111-4111-8111-111111111111',
        ])
            ->assertStatus(422);

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $unsorted->id]);
    }

    public function test_an_empty_unsorted_shelf_can_be_deleted(): void
    {
        [$h, $location] = $this->memberLocation();
        $unsorted = $location->unsortedShelf();

        // Nothing precious about it once empty — it is recreated on demand.
        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$unsorted->id}", [
            'deletion_batch_id' => '11111111-1111-4111-8111-111111111111',
        ])
            ->assertOk();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $unsorted->id]);
    }
}
