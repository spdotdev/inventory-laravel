<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * HouseholdPolicy documents "every mutating storage-structure route authorizes
 * against `restructure`" as an invariant. RestructurePolicyTest only exercises
 * the policy method in isolation (Gate::forUser(...)->allows(...)) — it can't
 * catch a controller that forgot to call Gate::authorize() at all, because the
 * policy grants any member today and household.member already 404s
 * non-members, so a missing call site is currently invisible from the outside.
 *
 * These tests close that gap: they swap in a policy that DENIES `restructure`
 * (standing in for a future demoted/read-only member) and assert each of the
 * four endpoints actually calls the gate and turns the denial into a 403.
 * Without `Gate::authorize(...)` as the first line, each of these would still
 * succeed (200/201) even though restructure is denied.
 */
class RestructureGateCoverageTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function memberHousehold(): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return $household;
    }

    /** Stand in for a future role that is a member but may not restructure. */
    private function denyRestructure(): void
    {
        Gate::policy(Household::class, get_class(new class
        {
            public function restructure(): bool
            {
                return false;
            }
        }));
    }

    public function test_shelf_store_is_gated(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $this->denyRestructure();

        $this->postJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves", [
            'name' => 'Top',
        ])->assertStatus(403);
    }

    public function test_location_store_is_gated(): void
    {
        $h = $this->memberHousehold();
        $this->denyRestructure();

        $this->postJson("{$this->base}/households/{$h->id}/locations", [
            'name' => 'Chest',
            'type' => 'freezer',
        ])->assertStatus(403);
    }

    public function test_location_update_is_gated(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $this->denyRestructure();

        $this->putJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'name' => 'Renamed',
        ])->assertStatus(403);
    }

    public function test_location_destroy_is_gated(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $this->denyRestructure();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}")
            ->assertStatus(403);
    }
}
