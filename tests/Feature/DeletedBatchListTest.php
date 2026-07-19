<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * API twin of WebRestoreTest's "Recently deleted" coverage — the closer for
 * GAP6-H5/ROADMAP's "recently-deleted browser" gap: Android had no way to
 * discover a restorable batch once its snackbar Undo timed out.
 */
class DeletedBatchListTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $batch = '44444444-4444-4444-8444-444444444444';

    private function memberHousehold(string $email = 'stan@example.test', string $role = 'admin'): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => $email, 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => Household::generateUniqueJoinCode()]);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return $household;
    }

    public function test_lists_a_restorable_batch_with_its_counts(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->getJson("{$this->base}/households/{$h->id}/deleted")
            ->assertOk()
            ->assertJsonPath('data.0.batch', $this->batch)
            ->assertJsonPath('data.0.shelves', 1)
            ->assertJsonPath('data.0.products', 1)
            ->assertJsonPath('data.0.total', 2);
    }

    public function test_a_restored_batch_no_longer_appears(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")->assertOk();

        $this->getJson("{$this->base}/households/{$h->id}/deleted")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_plain_member_can_view_the_list_read_only_gate_not_restructure(): void
    {
        $h = $this->memberHousehold(role: 'member');
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
            'deletion_batch_id' => $this->batch,
        ])->assertForbidden();

        // A member can't restructure, so seed the batch as an admin, then re-auth as
        // the member and confirm they can still list it (view-only, unlike restore).
        $h->users()->updateExistingPivot(
            User::where('email', 'stan@example.test')->sole()->getKey(),
            ['role' => 'admin'],
        );
        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();
        $h->users()->updateExistingPivot(
            User::where('email', 'stan@example.test')->sole()->getKey(),
            ['role' => 'member'],
        );

        $this->getJson("{$this->base}/households/{$h->id}/deleted")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_non_member_cannot_view_another_households_deleted_batches(): void
    {
        $owner = $this->memberHousehold('owner@example.test');
        $location = $owner->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $this->deleteJson("{$this->base}/households/{$owner->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->memberHousehold('outsider@example.test');

        $this->getJson("{$this->base}/households/{$owner->id}/deleted")
            ->assertNotFound();
    }
}
