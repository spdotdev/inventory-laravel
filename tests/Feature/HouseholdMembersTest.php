<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdMembersTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function memberWithRole(Household $household, string $role, string $name = 'U'): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => $name, 'email' => "u{$n}@example.test", 'password' => 'secret-password']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return $user;
    }

    public function test_any_member_can_list_the_roster(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'AAAA-1111']);
        $owner = $this->memberWithRole($household, 'owner', 'Owner');
        $this->memberWithRole($household, 'member', 'Plain');
        Sanctum::actingAs($owner);

        $this->getJson("{$this->base}/households/{$household->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_an_admin_can_promote_a_member_to_admin(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'BBBB-2222']);
        $admin = $this->memberWithRole($household, 'admin');
        $member = $this->memberWithRole($household, 'member');
        Sanctum::actingAs($admin);

        $this->patchJson("{$this->base}/households/{$household->id}/members/{$member->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_a_member_cannot_change_anyones_role(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'CCCC-3333']);
        $member = $this->memberWithRole($household, 'member');
        $otherMember = $this->memberWithRole($household, 'member');
        Sanctum::actingAs($member);

        $this->patchJson("{$this->base}/households/{$household->id}/members/{$otherMember->id}", ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_setting_role_to_owner_via_patch_is_rejected(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'DDDD-4444']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($owner);

        $this->patchJson("{$this->base}/households/{$household->id}/members/{$admin->id}", ['role' => 'owner'])
            ->assertStatus(422);
    }

    public function test_the_owners_own_row_cannot_be_patched(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'EEEE-5555']);
        $owner = $this->memberWithRole($household, 'owner');
        Sanctum::actingAs($owner);

        $this->patchJson("{$this->base}/households/{$household->id}/members/{$owner->id}", ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_an_admin_can_remove_a_member(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'FFFF-6666']);
        $admin = $this->memberWithRole($household, 'admin');
        $member = $this->memberWithRole($household, 'member');
        Sanctum::actingAs($admin);

        $this->deleteJson("{$this->base}/households/{$household->id}/members/{$member->id}")
            ->assertOk();

        $this->assertNull($household->fresh()->roleOf($member));
    }

    public function test_removing_the_owner_is_forbidden(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'GGGG-7777']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("{$this->base}/households/{$household->id}/members/{$owner->id}")
            ->assertForbidden();
    }

    public function test_removing_a_non_member_is_404(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'HHHH-8888']);
        $admin = $this->memberWithRole($household, 'admin');
        $stranger = User::create(['name' => 'S', 'email' => 's@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($admin);

        $this->deleteJson("{$this->base}/households/{$household->id}/members/{$stranger->id}")
            ->assertNotFound();
    }

    public function test_the_owner_can_transfer_ownership(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'IIII-9999']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($owner);

        $this->postJson("{$this->base}/households/{$household->id}/transfer-ownership", ['user_id' => $admin->id])
            ->assertOk();

        $this->assertSame('owner', $household->fresh()->roleOf($admin));
        $this->assertSame('admin', $household->fresh()->roleOf($owner));
    }

    public function test_an_admin_cannot_transfer_ownership(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'JJJJ-0000']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($admin);

        $this->postJson("{$this->base}/households/{$household->id}/transfer-ownership", ['user_id' => $owner->id])
            ->assertForbidden();
    }

    public function test_the_owner_cannot_leave_without_transferring_first(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'KKKK-1111']);
        $owner = $this->memberWithRole($household, 'owner');
        $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($owner);

        $this->deleteJson("{$this->base}/households/{$household->id}/leave")
            ->assertStatus(409);
    }

    public function test_a_non_owner_can_still_leave_freely(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'LLLL-2222']);
        $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("{$this->base}/households/{$household->id}/leave")
            ->assertOk();
    }
}
