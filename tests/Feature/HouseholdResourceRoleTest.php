<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdResourceRoleTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    public function test_the_list_endpoint_reports_the_callers_own_role_and_capabilities(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'AAAA-1111']);
        $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => 'secret-password']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);
        Sanctum::actingAs($member);

        $this->getJson("{$this->base}/households")
            ->assertOk()
            ->assertJsonPath('data.0.role', 'member')
            ->assertJsonPath('data.0.can_restructure', false)
            ->assertJsonPath('data.0.can_manage_members', false);
    }

    public function test_an_admin_sees_true_capabilities(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'BBBB-2222']);
        $admin = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'secret-password']);
        $household->users()->attach($admin->getKey(), ['joined_at' => now(), 'role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson("{$this->base}/households")
            ->assertOk()
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.0.can_restructure', true)
            ->assertJsonPath('data.0.can_manage_members', true);
    }
}
