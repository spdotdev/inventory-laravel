<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class CreateHouseholdCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_household_and_prints_the_join_code(): void
    {
        $this->artisan('inventory:household:create', ['name' => 'Garage'])
            ->expectsOutputToContain('Join code:')
            ->assertExitCode(0);

        $this->assertDatabaseHas('inventory_households', ['name' => 'Garage']);
        $this->assertNotEmpty(Household::query()->where('name', 'Garage')->value('join_code'));
    }

    public function test_it_adds_an_existing_member(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);

        $this->artisan('inventory:household:create', ['name' => 'Garage', '--member' => ['stan@example.test']])
            ->assertExitCode(0);

        $household = Household::query()->where('name', 'Garage')->firstOrFail();
        $this->assertTrue($household->users()->whereKey($user->getKey())->exists());
    }

    public function test_it_warns_but_succeeds_for_an_unknown_member(): void
    {
        $this->artisan('inventory:household:create', ['name' => 'Garage', '--member' => ['ghost@example.test']])
            ->expectsOutputToContain('No user with email ghost@example.test')
            ->assertExitCode(0);

        $this->assertDatabaseHas('inventory_households', ['name' => 'Garage']);
    }
}
