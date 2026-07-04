<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * The inventory:household:* operator CLI beyond create: list, add-member,
 * regenerate-code. Mirrors CreateHouseholdCommandTest conventions.
 */
class HouseholdCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_households_with_join_code_and_member_count(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        // expectsTable compares the rendered table structurally (through the same
        // renderer), so it isn't sensitive to terminal-width column wrapping the way
        // a raw expectsOutputToContain on a cell value is.
        $this->artisan('inventory:household:list')
            ->expectsTable(
                ['ID', 'Name', 'Join code', 'Members'],
                [[$household->id, 'Garage', 'AAAA-1111', 1]],
            )
            ->assertExitCode(0);
    }

    public function test_list_is_graceful_when_empty(): void
    {
        $this->artisan('inventory:household:list')
            ->expectsOutputToContain('No households yet.')
            ->assertExitCode(0);
    }

    public function test_add_member_attaches_an_existing_user(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);

        $this->artisan('inventory:household:add-member', [
            'household' => $household->id,
            'email' => ['stan@example.test'],
        ])->assertExitCode(0);

        $this->assertTrue($household->users()->whereKey($user->getKey())->exists());
    }

    public function test_add_member_is_idempotent_and_warns_on_unknown_email(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        $this->artisan('inventory:household:add-member', [
            'household' => $household->id,
            'email' => ['stan@example.test', 'ghost@example.test'],
        ])
            ->expectsOutputToContain('No user with email ghost@example.test')
            ->assertExitCode(0);

        // Re-adding an existing member must not create a duplicate pivot row.
        $this->assertSame(1, $household->users()->count());
    }

    public function test_add_member_fails_for_an_unknown_household(): void
    {
        $this->artisan('inventory:household:add-member', [
            'household' => 999,
            'email' => ['stan@example.test'],
        ])
            ->expectsOutputToContain('No household with id 999.')
            ->assertExitCode(1);
    }

    public function test_regenerate_code_rotates_the_join_code(): void
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);

        $this->artisan('inventory:household:regenerate-code', ['household' => $household->id])
            ->expectsOutputToContain('Old: AAAA-1111')
            ->assertExitCode(0);

        $this->assertNotSame('AAAA-1111', $household->refresh()->join_code);
    }

    public function test_regenerate_code_fails_for_an_unknown_household(): void
    {
        $this->artisan('inventory:household:regenerate-code', ['household' => 999])
            ->expectsOutputToContain('No household with id 999.')
            ->assertExitCode(1);
    }
}
