<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class WebHouseholdMembersTest extends TestCase
{
    use RefreshDatabase;

    private string $web = 'http://inventory.test/app';

    public function test_an_admin_can_promote_a_member_via_the_web_route(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'AAAA-1111']);
        $admin = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => bcrypt('secret-password')]);
        $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => bcrypt('secret-password')]);
        $household->users()->attach($admin->getKey(), ['joined_at' => now(), 'role' => 'admin']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($admin, 'inventory')
            ->put("{$this->web}/households/{$household->id}/members/{$member->id}", ['role' => 'admin'])
            ->assertRedirect();

        $this->assertSame('admin', $household->fresh()->roleOf($member));
    }

    public function test_a_member_cannot_promote_anyone(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'BBBB-2222']);
        $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => bcrypt('secret-password')]);
        $other = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => bcrypt('secret-password')]);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);
        $household->users()->attach($other->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($member, 'inventory')
            ->put("{$this->web}/households/{$household->id}/members/{$other->id}", ['role' => 'admin'])
            ->assertForbidden();
    }
}
