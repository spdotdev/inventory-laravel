<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/** Phase 2: PATCH /households/{household} — rename + user-chosen color/icon. */
class HouseholdThemeTest extends TestCase
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
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}", ['color' => 'teal', 'icon' => 'cottage'])
            ->assertOk()
            ->assertJsonPath('data.color', 'teal')
            ->assertJsonPath('data.icon', 'cottage');

        // Explicit null clears back to the client-derived default.
        $this->patchJson("{$this->base}/households/{$household->id}", ['color' => null])
            ->assertOk()
            ->assertJsonPath('data.color', null)
            ->assertJsonPath('data.icon', 'cottage');
    }

    public function test_rename_and_partial_updates_leave_other_fields_alone(): void
    {
        [$user, $household] = $this->memberSetup();
        $household->update(['color' => 'pink']);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}", ['name' => 'Beach house'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Beach house')
            ->assertJsonPath('data.color', 'pink');
    }

    public function test_unknown_palette_keys_are_rejected(): void
    {
        [$user, $household] = $this->memberSetup();
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/households/{$household->id}", ['color' => 'magenta'])
            ->assertUnprocessable();
        $this->patchJson("{$this->base}/households/{$household->id}", ['icon' => 'castle'])
            ->assertUnprocessable();
    }

    public function test_non_members_get_404(): void
    {
        [, $household] = $this->memberSetup();
        $stranger = User::create(['name' => 'S', 'email' => 's@example.test', 'password' => bcrypt('secret-password')]);
        Sanctum::actingAs($stranger);

        $this->patchJson("{$this->base}/households/{$household->id}", ['color' => 'teal'])
            ->assertNotFound();
        $this->assertNull($household->refresh()->color);
    }
}
