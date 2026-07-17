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

    private function memberWithRole(Household $household, string $role): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => "U{$n}", 'email' => "u{$n}@example.test", 'password' => 'secret-password']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return $user;
    }

    public function test_an_owner_may_restructure(): void
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $owner = $this->memberWithRole($household, 'owner');

        $this->assertTrue(Gate::forUser($owner)->allows('restructure', $household));
    }

    public function test_an_admin_may_restructure(): void
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'BBBB-2222']);
        $admin = $this->memberWithRole($household, 'admin');

        $this->assertTrue(Gate::forUser($admin)->allows('restructure', $household));
    }

    public function test_a_member_may_not_restructure(): void
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'CCCC-3333']);
        $member = $this->memberWithRole($household, 'member');

        $this->assertFalse(Gate::forUser($member)->allows('restructure', $household));
    }

    public function test_a_non_member_may_not_restructure(): void
    {
        // household.member 404s a non-member before the policy ever runs; the
        // policy still denies them so the rule holds if that middleware is ever
        // removed from a route by mistake.
        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Private', 'join_code' => 'ZZZZ-9999']);

        $this->assertFalse(Gate::forUser($outsider)->allows('restructure', $household));
    }
}
