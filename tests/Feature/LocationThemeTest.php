<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Phase 2 storage location theming — mirrors ShelfThemeTest one-for-one:
 * PATCH .../locations/{location} accepts user-chosen color/icon palette
 * keys, sharing the household enums so the two never drift. Unlike shelves,
 * locations have no is_system concept, so there is no themed-system-object
 * guard to test here.
 */
class LocationThemeTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    /** @return array{User, Household} */
    private function memberSetup(): array
    {
        $user = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => bcrypt('secret-password')]);
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'admin']);

        return [$user, $household];
    }

    public function test_member_can_set_and_clear_the_theme(): void
    {
        [$user, $household] = $this->memberSetup();
        $location = $household->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}", ['color' => 'teal', 'icon' => 'cottage'])
            ->assertOk()
            ->assertJsonPath('data.color', 'teal')
            ->assertJsonPath('data.icon', 'cottage');

        // Explicit null clears back to the client-derived default.
        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}", ['color' => null])
            ->assertOk()
            ->assertJsonPath('data.color', null)
            ->assertJsonPath('data.icon', 'cottage');
    }

    public function test_rename_and_partial_updates_leave_other_fields_alone(): void
    {
        [$user, $household] = $this->memberSetup();
        $location = $household->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry, 'color' => 'pink']);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.color', 'pink');
    }

    public function test_unknown_palette_keys_are_rejected(): void
    {
        [$user, $household] = $this->memberSetup();
        $location = $household->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}", ['color' => 'magenta'])
            ->assertUnprocessable();
        $this->patchJson("{$this->base}/households/{$household->id}/locations/{$location->id}", ['icon' => 'castle'])
            ->assertUnprocessable();
    }

    public function test_theme_is_present_in_index_and_show(): void
    {
        [$user, $household] = $this->memberSetup();
        $location = $household->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry, 'color' => 'amber', 'icon' => 'box']);
        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/households/{$household->id}/locations")
            ->assertOk()
            ->assertJsonPath('data.0.color', 'amber')
            ->assertJsonPath('data.0.icon', 'box');

        $this->getJson("{$this->base}/households/{$household->id}/locations/{$location->id}")
            ->assertOk()
            ->assertJsonPath('data.color', 'amber')
            ->assertJsonPath('data.icon', 'box');
    }
}
