<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Web parity Task 2: reorder on the web. Mirrors tests/Feature/ReorderTest.php
 * (the API twin) — same invariant matrix — via the web routes added to
 * routes/web.php, both surfaces sharing Support\Reorderer.
 */
class WebReorderTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test';

    private function user(string $email = 'web@example.test'): User
    {
        return User::create(['name' => 'Web', 'email' => $email, 'password' => bcrypt('secret-password')]);
    }

    /** @return array{0: User, 1: Household} */
    private function adminHousehold(): array
    {
        $user = $this->user();
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'admin']);

        return [$user, $household];
    }

    public function test_locations_can_be_reordered_via_json_patch(): void
    {
        [$user, $household] = $this->adminHousehold();
        $a = $household->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $household->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);
        $c = $household->locations()->create(['name' => 'Ccc', 'type' => StorageType::Freezer]);

        $this->actingAs($user, 'inventory')
            ->patchJson(route('inventory.web.locations.reorder', $household), [
                'ids' => [$c->id, $a->id, $b->id],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Order saved.');

        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $c->id, 'position' => 0]);
        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $a->id, 'position' => 1]);
        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $b->id, 'position' => 2]);
    }

    public function test_locations_can_be_reordered_via_the_non_js_form_fallback(): void
    {
        // Plain spoofed-PATCH POST, matching the <noscript> form the Blade
        // view renders — no Accept: application/json header, so the
        // controller must take the redirect branch, not the JSON one.
        [$user, $household] = $this->adminHousehold();
        $a = $household->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $household->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.locations.reorder', $household), [
                '_method' => 'PATCH',
                'ids' => [$b->id, $a->id],
            ])
            ->assertRedirect(route('inventory.web.households.show', $household).'#locations');

        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $b->id, 'position' => 0]);
        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $a->id, 'position' => 1]);
    }

    public function test_shelves_can_be_reordered_via_json_patch(): void
    {
        [$user, $household] = $this->adminHousehold();
        $loc = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $mid = $loc->shelves()->create(['name' => 'Middle', 'position' => 1]);
        $bot = $loc->shelves()->create(['name' => 'Bottom', 'position' => 2]);

        $this->actingAs($user, 'inventory')
            ->patchJson(route('inventory.web.shelves.reorder', [$household, $loc]), [
                'ids' => [$bot->id, $top->id, $mid->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('inventory_shelves', ['id' => $bot->id, 'position' => 0]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $top->id, 'position' => 1]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $mid->id, 'position' => 2]);
    }

    public function test_shelf_reorder_excludes_the_system_shelf(): void
    {
        [$user, $household] = $this->adminHousehold();
        $loc = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $bot = $loc->shelves()->create(['name' => 'Bottom', 'position' => 1]);
        $unsorted = $loc->unsortedShelf();

        // Omitting the system shelf still succeeds...
        $this->actingAs($user, 'inventory')
            ->patchJson(route('inventory.web.shelves.reorder', [$household, $loc]), [
                'ids' => [$bot->id, $top->id],
            ])
            ->assertOk();
        $this->assertDatabaseHas('inventory_shelves', ['id' => $unsorted->id, 'position' => 0]);

        // ...but including it is rejected, matching Reorderer::shelves.
        $this->actingAs($user, 'inventory')
            ->patchJson(route('inventory.web.shelves.reorder', [$household, $loc]), [
                'ids' => [$unsorted->id, $bot->id, $top->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_reorder_rejects_a_partial_list(): void
    {
        [$user, $household] = $this->adminHousehold();
        $a = $household->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $household->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);

        $this->actingAs($user, 'inventory')
            ->patchJson(route('inventory.web.locations.reorder', $household), [
                'ids' => [$a->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_reorder_rejects_an_id_from_another_household(): void
    {
        [$user, $household] = $this->adminHousehold();
        $mine = $household->locations()->create(['name' => 'Mine', 'type' => StorageType::Fridge]);

        $other = Household::query()->create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $theirs = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);

        $this->actingAs($user, 'inventory')
            ->patchJson(route('inventory.web.locations.reorder', $household), [
                'ids' => [$theirs->id, $mine->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_non_members_get_404_for_location_reorder(): void
    {
        [, $household] = $this->adminHousehold();
        $a = $household->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);

        $stranger = $this->user('stranger@example.test');
        $this->actingAs($stranger, 'inventory')
            ->patchJson(route('inventory.web.locations.reorder', $household), ['ids' => [$a->id]])
            ->assertNotFound();
    }

    public function test_a_plain_member_cannot_reorder_locations(): void
    {
        // household.member only gates membership; restructure is Owner/Admin
        // only, same gate as store()/destroy() on this controller pair.
        $user = $this->user();
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'member']);
        $a = $household->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $household->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);

        $this->actingAs($user, 'inventory')
            ->patchJson(route('inventory.web.locations.reorder', $household), ['ids' => [$b->id, $a->id]])
            ->assertForbidden();
    }

    public function test_a_plain_member_cannot_reorder_shelves(): void
    {
        $user = $this->user();
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'member']);
        $loc = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $bot = $loc->shelves()->create(['name' => 'Bottom', 'position' => 1]);

        $this->actingAs($user, 'inventory')
            ->patchJson(route('inventory.web.shelves.reorder', [$household, $loc]), ['ids' => [$bot->id, $top->id]])
            ->assertForbidden();
    }

    public function test_household_page_reflects_manual_order(): void
    {
        [$user, $household] = $this->adminHousehold();
        $a = $household->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $household->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);

        $this->actingAs($user, 'inventory')
            ->patchJson(route('inventory.web.locations.reorder', $household), ['ids' => [$b->id, $a->id]])
            ->assertOk();

        $html = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.households.show', $household))
            ->assertOk()
            ->getContent();

        // Bbb now sorts before Aaa in the server-rendered order.
        $this->assertGreaterThan(0, strpos((string) $html, 'Bbb'));
        $this->assertTrue(strpos((string) $html, 'Bbb') < strpos((string) $html, 'Aaa'));
    }
}
