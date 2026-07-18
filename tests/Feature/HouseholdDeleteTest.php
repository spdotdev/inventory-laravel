<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Owner-initiated household delete (GAP: the solo Owner previously had no way
 * to leave or delete their own household — HouseholdPolicy::delete existed but
 * was never routed). Server-side typed-name confirmation on both surfaces.
 */
class HouseholdDeleteTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $web = 'http://inventory.test/app';

    private function member(Household $household, string $role, string $name = 'U'): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => $name, 'email' => "d{$n}@example.test", 'password' => bcrypt('secret-password')]);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return $user;
    }

    public function test_the_owner_can_delete_the_household_with_name_confirmation(): void
    {
        $household = Household::create(['name' => 'Doomed', 'join_code' => 'AAAA-1111']);
        $owner = $this->member($household, 'owner');
        $location = $household->locations()->create(['name' => 'Fridge', 'type' => 'fridge']);
        Sanctum::actingAs($owner);

        $this->deleteJson("{$this->base}/households/{$household->id}", ['name' => 'Doomed'])
            ->assertOk();

        $this->assertDatabaseMissing('inventory_households', ['id' => $household->id]);
        $this->assertDatabaseMissing('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_a_wrong_name_confirmation_is_rejected(): void
    {
        $household = Household::create(['name' => 'Sacred', 'join_code' => 'BBBB-2222']);
        $owner = $this->member($household, 'owner');
        Sanctum::actingAs($owner);

        $this->deleteJson("{$this->base}/households/{$household->id}", ['name' => 'sacred '])
            ->assertStatus(422);

        $this->assertDatabaseHas('inventory_households', ['id' => $household->id]);
    }

    public function test_an_admin_cannot_delete_the_household(): void
    {
        $household = Household::create(['name' => 'Guarded', 'join_code' => 'CCCC-3333']);
        $this->member($household, 'owner');
        $admin = $this->member($household, 'admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("{$this->base}/households/{$household->id}", ['name' => 'Guarded'])
            ->assertForbidden();
    }

    public function test_a_non_member_gets_404(): void
    {
        $household = Household::create(['name' => 'Hidden', 'join_code' => 'DDDD-4444']);
        $this->member($household, 'owner');
        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => bcrypt('secret-password')]);
        Sanctum::actingAs($outsider);

        $this->deleteJson("{$this->base}/households/{$household->id}", ['name' => 'Hidden'])
            ->assertNotFound();
    }

    public function test_the_owner_can_delete_via_the_web_with_name_confirmation(): void
    {
        $household = Household::create(['name' => 'WebDoomed', 'join_code' => 'EEEE-5555']);
        $owner = $this->member($household, 'owner');
        $this->member($household, 'member');

        $this->actingAs($owner, 'inventory')
            ->delete("{$this->web}/households/{$household->id}", ['confirm_name' => 'WebDoomed'])
            ->assertRedirect();

        $this->assertDatabaseMissing('inventory_households', ['id' => $household->id]);
    }

    public function test_web_delete_with_wrong_name_bounces_back_with_an_error(): void
    {
        $household = Household::create(['name' => 'WebSacred', 'join_code' => 'FFFF-6666']);
        $owner = $this->member($household, 'owner');

        $this->actingAs($owner, 'inventory')
            ->delete("{$this->web}/households/{$household->id}", ['confirm_name' => 'nope'])
            ->assertSessionHasErrors('confirm_name');

        $this->assertDatabaseHas('inventory_households', ['id' => $household->id]);
    }

    /**
     * GAP-4 L4: the household page also has a location-add form validating
     * `name`. The delete-confirm field is `confirm_name`, not `name`, so a
     * failed delete-confirm never lands a bogus 'name' error under the
     * unrelated location-add input.
     */
    public function test_web_delete_confirm_error_does_not_collide_with_location_name(): void
    {
        $household = Household::create(['name' => 'WebCollide', 'join_code' => 'GGGG-7777']);
        $owner = $this->member($household, 'owner');

        $this->actingAs($owner, 'inventory')
            ->delete("{$this->web}/households/{$household->id}", ['confirm_name' => 'nope'])
            ->assertSessionHasErrors('confirm_name')
            ->assertSessionDoesntHaveErrors('name');
    }
}
