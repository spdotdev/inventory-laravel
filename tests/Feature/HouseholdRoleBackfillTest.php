<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdRoleBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_memberships_default_to_member(): void
    {
        $user = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        $this->assertSame(
            'member',
            DB::table('inventory_household_user')
                ->where('household_id', $household->id)
                ->where('user_id', $user->id)
                ->value('role'),
        );
    }

    public function test_the_column_accepts_owner_and_admin(): void
    {
        $user = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Home', 'join_code' => 'BBBB-2222']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        $this->assertSame(
            'owner',
            DB::table('inventory_household_user')
                ->where('household_id', $household->id)
                ->where('user_id', $user->id)
                ->value('role'),
        );
    }
}
