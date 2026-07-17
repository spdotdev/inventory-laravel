<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Regression tests for the owner-less-household bug (found by a stability
 * audit the day the roles release shipped): store() attached the creator
 * without a role, leaving brand-new households with no Owner at all.
 */
class HouseholdOwnerInvariantTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $web = 'http://inventory.test/app';

    private function user(string $name = 'U'): User
    {
        static $n = 0;
        $n++;

        return User::create(['name' => $name, 'email' => "u{$n}@example.test", 'password' => bcrypt('secret-password')]);
    }

    public function test_the_creator_of_a_household_becomes_its_owner(): void
    {
        $creator = $this->user('Creator');
        Sanctum::actingAs($creator);

        $response = $this->postJson("{$this->base}/households", ['name' => 'Fresh'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.can_restructure', true)
            ->assertJsonPath('data.can_manage_members', true);

        $household = Household::query()->findOrFail($response->json('data.id'));
        $this->assertSame('owner', $household->roleOf($creator));
    }

    public function test_the_web_creator_also_becomes_owner(): void
    {
        $creator = $this->user('WebCreator');

        $this->actingAs($creator, 'inventory')
            ->post("{$this->web}/households", ['name' => 'Web Fresh'])
            ->assertRedirect();

        $household = Household::query()->where('name', 'Web Fresh')->firstOrFail();
        $this->assertSame('owner', $household->roleOf($creator));
    }

    public function test_joining_an_ownerless_household_promotes_the_joiner_to_owner(): void
    {
        // An owner-less household is normally impossible, but the artisan
        // command can create one with no members, and the v0.1.12 window
        // shipped owner-less creates. First member to arrive heals it.
        $household = Household::create(['name' => 'Orphan', 'join_code' => 'AAAA-1111']);
        $joiner = $this->user('Joiner');
        Sanctum::actingAs($joiner);

        $this->postJson("{$this->base}/households/join", ['code' => 'AAAA-1111'])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');

        $this->assertSame('owner', $household->fresh()->roleOf($joiner));
    }

    public function test_joining_a_household_with_an_owner_still_lands_as_member(): void
    {
        $household = Household::create(['name' => 'Normal', 'join_code' => 'BBBB-2222']);
        $owner = $this->user('Owner');
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        $joiner = $this->user('Joiner2');
        Sanctum::actingAs($joiner);

        $this->postJson("{$this->base}/households/join", ['code' => 'BBBB-2222'])
            ->assertOk()
            ->assertJsonPath('data.role', 'member');
    }

    public function test_the_repair_migration_promotes_the_earliest_member_of_an_ownerless_household(): void
    {
        $household = Household::create(['name' => 'Broken', 'join_code' => 'CCCC-3333']);
        $early = $this->user('Early');
        $late = $this->user('Late');
        // Simulate the bug window: both attached as plain members.
        $household->users()->attach($early->getKey(), ['joined_at' => now()->subDay(), 'role' => 'member']);
        $household->users()->attach($late->getKey(), ['joined_at' => now(), 'role' => 'member']);

        // A healthy household that must NOT be touched.
        $healthy = Household::create(['name' => 'Healthy', 'join_code' => 'DDDD-4444']);
        $healthyOwner = $this->user('HealthyOwner');
        $healthyAdmin = $this->user('HealthyAdmin');
        $healthy->users()->attach($healthyOwner->getKey(), ['joined_at' => now()->subDay(), 'role' => 'owner']);
        $healthy->users()->attach($healthyAdmin->getKey(), ['joined_at' => now(), 'role' => 'admin']);

        $migration = require __DIR__.'/../../database/migrations/2026_07_17_100000_repair_ownerless_households.php';
        $migration->up();

        $this->assertSame('owner', $household->fresh()->roleOf($early));
        $this->assertSame('member', $household->fresh()->roleOf($late));
        $this->assertSame('owner', $healthy->fresh()->roleOf($healthyOwner));
        $this->assertSame('admin', $healthy->fresh()->roleOf($healthyAdmin));

        // Idempotent: running it again changes nothing.
        $migration->up();
        $this->assertSame(1, DB::table('inventory_household_user')->where('household_id', $household->id)->where('role', 'owner')->count());
    }

    public function test_a_member_cannot_rename_or_theme_the_household(): void
    {
        $household = Household::create(['name' => 'Locked', 'join_code' => 'EEEE-5555']);
        $owner = $this->user('LockOwner');
        $member = $this->user('LockMember');
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        Sanctum::actingAs($member);
        $this->patchJson("{$this->base}/households/{$household->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Locked', $household->fresh()->name);
    }

    public function test_an_admin_can_still_rename_the_household(): void
    {
        $household = Household::create(['name' => 'Renameable', 'join_code' => 'FFFF-6666']);
        $admin = $this->user('RenameAdmin');
        $household->users()->attach($admin->getKey(), ['joined_at' => now(), 'role' => 'admin']);

        Sanctum::actingAs($admin);
        $this->patchJson("{$this->base}/households/{$household->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_member_mutations_broadcast_household_changed(): void
    {
        $household = Household::create(['name' => 'Live', 'join_code' => 'GGGG-7777']);
        $owner = $this->user('LiveOwner');
        $member = $this->user('LiveMember');
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        // Fake only after setup: Household::create itself fires the observer.
        Event::fake([HouseholdChanged::class]);
        Sanctum::actingAs($owner);

        $this->patchJson("{$this->base}/households/{$household->id}/members/{$member->id}", ['role' => 'admin'])->assertOk();
        $this->postJson("{$this->base}/households/{$household->id}/transfer-ownership", ['user_id' => $member->id])->assertOk();

        Event::assertDispatchedTimes(HouseholdChanged::class, 2);
    }

    public function test_leaving_broadcasts_when_members_remain(): void
    {
        $household = Household::create(['name' => 'Leavers', 'join_code' => 'HHHH-8888']);
        $owner = $this->user('StayOwner');
        $admin = $this->user('GoAdmin');
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);
        $household->users()->attach($admin->getKey(), ['joined_at' => now(), 'role' => 'admin']);

        Event::fake([HouseholdChanged::class]);
        Sanctum::actingAs($admin);
        $this->deleteJson("{$this->base}/households/{$household->id}/leave")->assertOk();

        Event::assertDispatched(HouseholdChanged::class);
    }

    public function test_web_leave_blocks_the_sole_owner(): void
    {
        // The API got this guard in the roles release; the web twin was
        // missed — an Owner could leave via the browser and orphan the
        // household.
        $household = Household::create(['name' => 'WebGuard', 'join_code' => 'JJJJ-9999']);
        $owner = $this->user('WebOwner');
        $admin = $this->user('WebAdmin');
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);
        $household->users()->attach($admin->getKey(), ['joined_at' => now(), 'role' => 'admin']);

        $this->actingAs($owner, 'inventory')
            ->delete("{$this->web}/households/{$household->id}/leave")
            ->assertSessionHasErrors('leave');

        $this->assertSame('owner', $household->fresh()->roleOf($owner));
    }

    public function test_web_leave_deletes_an_emptied_household(): void
    {
        $household = Household::create(['name' => 'WebEmpty', 'join_code' => 'KKKK-0000']);
        $member = $this->user('WebLast');
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->actingAs($member, 'inventory')
            ->delete("{$this->web}/households/{$household->id}/leave")
            ->assertRedirect();

        $this->assertDatabaseMissing('inventory_households', ['id' => $household->id]);
    }
}
