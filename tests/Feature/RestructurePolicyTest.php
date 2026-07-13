<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class RestructurePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_may_restructure(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        $this->assertTrue(Gate::forUser($user)->allows('restructure', $household));
    }

    public function test_a_non_member_may_not_restructure(): void
    {
        // In practice household.member 404s a non-member long before the policy
        // runs. The policy still denies them, so the rule holds if that
        // middleware is ever removed from a route by mistake.
        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Private', 'join_code' => 'ZZZZ-9999']);

        $this->assertFalse(Gate::forUser($outsider)->allows('restructure', $household));
    }
}
