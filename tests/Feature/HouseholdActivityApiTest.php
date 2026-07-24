<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdActivityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_view_their_household_activity(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan1@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        // The Household.created observer automatically logs a 'household.created' entry.
        // Manually create an 'other' entry with a different household_id to verify filtering.
        ActivityLogEntry::create(['household_id' => 999, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 999, 'subject_label' => 'Other']);

        $response = $this->actingAs($user)
            ->getJson("http://inventory.test/api/v1/households/{$household->getKey()}/activity")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Casa', $response->json('data.0.subject_label'));
    }

    public function test_a_non_member_gets_a_404_not_a_403(): void
    {
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $outsider = User::create(['name' => 'Stan', 'email' => 'stan2@example.test', 'password' => 'secret-password']);

        $this->actingAs($outsider)
            ->getJson("http://inventory.test/api/v1/households/{$household->getKey()}/activity")
            ->assertStatus(404);
    }

    public function test_filters_by_action_within_the_household(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan3@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Casa', 'join_code' => 'ABC123']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        // The Household.created observer automatically logs a 'household.created' entry.
        // Manually create a 'member.added' entry to verify action filtering works.
        ActivityLogEntry::create(['household_id' => $household->getKey(), 'action' => 'member.added', 'subject_type' => 'HouseholdUserPivot', 'subject_id' => $user->getKey(), 'subject_label' => $user->name]);

        $response = $this->actingAs($user)
            ->getJson("http://inventory.test/api/v1/households/{$household->getKey()}/activity?action=member.added")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('member.added', $response->json('data.0.action'));
    }
}
