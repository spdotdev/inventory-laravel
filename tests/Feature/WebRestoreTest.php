<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class WebRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/app';

    private function member(string $role = 'admin'): array
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'inventory');

        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return [$user, $household];
    }

    public function test_deleting_a_shelf_on_the_web_flashes_an_undo_batch_that_restores_it(): void
    {
        [, $household] = $this->member();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $delete = $this->delete("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
        ])->assertRedirect();

        $delete->assertSessionHas('undo');
        $batch = session('undo')['batch'];
        $this->assertSame((int) $household->getKey(), session('undo')['household']);

        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);

        $this->post("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertRedirect();

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_deleting_a_location_on_the_web_flashes_an_undo_batch_that_restores_the_whole_subtree(): void
    {
        [, $household] = $this->member();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->delete("{$this->base}/households/{$household->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
        ])->assertRedirect();

        $batch = session('undo')['batch'];

        $this->post("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertRedirect(route('inventory.web.households.show', $household).'#recently-deleted');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_deleting_a_product_on_the_web_flashes_an_undo_batch_that_restores_it(): void
    {
        [, $household] = $this->member();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->delete("{$this->base}/households/{$household->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertRedirect();

        $batch = session('undo')['batch'];

        $this->post("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertRedirect();

        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_undoing_a_batch_whose_parent_died_later_surfaces_the_api_style_409_message_loudly(): void
    {
        [, $household] = $this->member();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->delete("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
        ])->assertRedirect();
        $shelfBatch = session('undo')['batch'];

        // Kill the location AFTER, in a separate gesture/batch.
        $this->delete("{$this->base}/households/{$household->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
        ])->assertRedirect();

        $response = $this->post("{$this->base}/households/{$household->id}/restore/{$shelfBatch}")
            ->assertRedirect();

        $response->assertSessionHasErrors('restore');
        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
    }

    public function test_undoing_an_unknown_batch_is_a_loud_error_not_a_silent_no_op(): void
    {
        [, $household] = $this->member();

        $this->post("{$this->base}/households/{$household->id}/restore/33333333-3333-4333-8333-333333333333")
            ->assertRedirect()
            ->assertSessionHasErrors('restore');
    }

    public function test_a_plain_member_cannot_restore_an_unknown_batch(): void
    {
        // An unknown batch owner falls straight through to the existing
        // nothing-to-restore path rather than a 403 — see
        // Api\RestoreController's docblock: a Member probing a guessed batch
        // id must not be able to tell "403 = it once existed" apart from
        // "409 = it never did".
        [, $household] = $this->member('member');

        $this->post("{$this->base}/households/{$household->id}/restore/33333333-3333-4333-8333-333333333333")
            ->assertRedirect()
            ->assertSessionHasErrors('restore');
    }

    public function test_a_plain_member_cannot_restore_someone_elses_batch(): void
    {
        [$member, $household] = $this->member('member');
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secret-password']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $batch = '33333333-3333-4333-8333-333333333333';
        $location->deletion_batch_id = $batch;
        $location->deleted_by = $owner->getKey();
        $location->save();
        $location->delete();

        $this->actingAs($member, 'inventory');
        $this->post("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertForbidden();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_a_plain_member_can_restore_a_batch_they_deleted_themselves(): void
    {
        [, $household] = $this->member('member');
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->delete("{$this->base}/households/{$household->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertRedirect();
        $batch = session('undo')['batch'];

        $this->post("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertRedirect();

        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_recently_deleted_view_lists_a_batch_and_its_restore_button_works(): void
    {
        [, $household] = $this->member();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->delete("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
        ])->assertRedirect();
        $batch = session('undo')['batch'];

        $this->get("{$this->base}/households/{$household->id}")
            ->assertOk()
            ->assertSee(__('Recently deleted'))
            ->assertSee(route('inventory.web.restore', [$household, $batch]), false);

        $this->post("{$this->base}/households/{$household->id}/restore/{$batch}")->assertRedirect();

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
    }

    public function test_recently_deleted_view_omits_batches_past_the_retention_window(): void
    {
        [, $household] = $this->member();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);

        $this->delete("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}")
            ->assertRedirect();

        // Push the trashed row's deleted_at outside the configured retention
        // window (defaults to 30 days) without touching the config, matching
        // how PruneDeletedCommand itself is tested against real elapsed time.
        Shelf::withTrashed()
            ->whereKey($shelf->getKey())
            ->update(['deleted_at' => now()->subDays(31)]);

        $this->get("{$this->base}/households/{$household->id}")
            ->assertOk()
            ->assertSee(__('Nothing deleted recently.'));
    }

    public function test_recently_deleted_section_is_hidden_from_a_plain_member(): void
    {
        [, $household] = $this->member('member');

        $this->get("{$this->base}/households/{$household->id}")
            ->assertOk()
            ->assertDontSee(__('Recently deleted'));
    }

    public function test_a_plain_member_deleting_a_product_still_gets_an_undo_flash_for_their_own_batch(): void
    {
        // Reversed since HouseholdPolicy::restoreBatch shipped (see this
        // file's other Member-restore tests): a Member always owns the
        // batch-of-one they just minted, so WebRestoreController being
        // restoreBatch-gated (not restructure-only) means the Undo button
        // this flash renders no longer 403s for them (audit #8's original
        // fix was to hide it; the real fix is letting them use it).
        [, $household] = $this->member(role: 'member');
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->delete("{$this->base}/households/{$household->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('undo');

        $batch = session('undo')['batch'];

        $this->post("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertRedirect();

        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }
}
