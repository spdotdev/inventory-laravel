<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdPolicyRolesTest extends TestCase
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

    public function test_manage_members_matches_restructure_owner_and_admin(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'AAAA-1111']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');
        $member = $this->memberWithRole($household, 'member');

        $this->assertTrue(Gate::forUser($owner)->allows('manageMembers', $household));
        $this->assertTrue(Gate::forUser($admin)->allows('manageMembers', $household));
        $this->assertFalse(Gate::forUser($member)->allows('manageMembers', $household));
    }

    public function test_only_the_owner_may_transfer_ownership_or_delete(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'BBBB-2222']);
        $owner = $this->memberWithRole($household, 'owner');
        $admin = $this->memberWithRole($household, 'admin');

        $this->assertTrue(Gate::forUser($owner)->allows('transferOwnership', $household));
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $household));
        $this->assertFalse(Gate::forUser($admin)->allows('transferOwnership', $household));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $household));
    }

    public function test_household_role_of_returns_null_for_a_non_member(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'CCCC-3333']);
        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'secret-password']);

        $this->assertNull($household->roleOf($outsider));
    }

    public function test_household_role_of_returns_the_members_role(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'DDDD-4444']);
        $admin = $this->memberWithRole($household, 'admin');

        $this->assertSame('admin', $household->roleOf($admin));
    }

    public function test_role_of_is_memoized_per_instance_and_only_queries_once(): void
    {
        $household = Household::create(['name' => 'H', 'join_code' => 'EEEE-5555']);
        $admin = $this->memberWithRole($household, 'admin');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $household->roleOf($admin);
        $queriesAfterFirstCall = count(DB::getQueryLog());

        $household->roleOf($admin);
        $queriesAfterSecondCall = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame(1, $queriesAfterFirstCall, 'The first roleOf() call should run exactly one query.');
        $this->assertSame(
            $queriesAfterFirstCall,
            $queriesAfterSecondCall,
            'The second roleOf() call on the same instance should be memoized and run no additional queries.'
        );
    }

    public function test_role_of_reuses_the_pivot_already_loaded_via_user_households(): void
    {
        $user = User::create(['name' => 'Multi', 'email' => 'multi@example.test', 'password' => 'secret-password']);

        foreach (range(1, 5) as $i) {
            $household = Household::create(['name' => "H{$i}", 'join_code' => sprintf('AAA%d-%04d', $i, $i)]);
            $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $households = $user->households()->orderBy('name')->get();
        $queriesForListing = count(DB::getQueryLog());

        foreach ($households as $household) {
            $this->assertSame('owner', $household->roleOf($user));
        }
        $queriesAfterRoleOfOnEach = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame(
            $queriesForListing,
            $queriesAfterRoleOfOnEach,
            'roleOf() should reuse the pivot already hydrated by $user->households() instead of running one query per household (N+1).'
        );
    }
}
