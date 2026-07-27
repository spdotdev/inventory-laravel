<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Phase 2 shelf theming — mirrors HouseholdThemeTest one-for-one: PATCH
 * .../shelves/{shelf} accepts user-chosen color/icon palette keys, sharing
 * the household enums so the two never drift.
 */
class ShelfThemeTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    /** @return array{User, Household, StorageLocation} */
    private function memberSetup(): array
    {
        $user = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => bcrypt('secret-password')]);
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'admin']);
        $location = $household->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);

        return [$user, $household, $location];
    }

    public function test_member_can_set_and_clear_the_theme(): void
    {
        [$user, $household, $location] = $this->memberSetup();
        $shelf = $location->shelves()->create(['name' => 'Top shelf', 'position' => 0]);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}", ['color' => 'teal', 'icon' => 'cottage'])
            ->assertOk()
            ->assertJsonPath('data.color', 'teal')
            ->assertJsonPath('data.icon', 'cottage');

        // Explicit null clears back to the client-derived default.
        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}", ['color' => null])
            ->assertOk()
            ->assertJsonPath('data.color', null)
            ->assertJsonPath('data.icon', 'cottage');
    }

    public function test_rename_and_partial_updates_leave_other_fields_alone(): void
    {
        [$user, $household, $location] = $this->memberSetup();
        $shelf = $location->shelves()->create(['name' => 'Top shelf', 'position' => 0, 'color' => 'pink']);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.color', 'pink');
    }

    public function test_unknown_palette_keys_are_rejected(): void
    {
        [$user, $household, $location] = $this->memberSetup();
        $shelf = $location->shelves()->create(['name' => 'Top shelf', 'position' => 0]);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}", ['color' => 'magenta'])
            ->assertUnprocessable();
        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}", ['icon' => 'castle'])
            ->assertUnprocessable();
    }

    public function test_the_unsorted_shelf_cannot_be_themed(): void
    {
        [$user, $household, $location] = $this->memberSetup();
        $unsorted = $location->unsortedShelf();
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$unsorted->id}", ['color' => 'teal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('color');

        $this->assertDatabaseHas('inventory_shelves', ['id' => $unsorted->id, 'color' => null]);
    }

    public function test_theme_is_present_in_index_and_show(): void
    {
        [$user, $household, $location] = $this->memberSetup();
        $shelf = $location->shelves()->create(['name' => 'Top shelf', 'position' => 0, 'color' => 'amber', 'icon' => 'box']);
        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves")
            ->assertOk()
            ->assertJsonPath('data.0.color', 'amber')
            ->assertJsonPath('data.0.icon', 'box');

        $this->getJson("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}")
            ->assertOk()
            ->assertJsonPath('data.color', 'amber')
            ->assertJsonPath('data.icon', 'box');
    }
}
