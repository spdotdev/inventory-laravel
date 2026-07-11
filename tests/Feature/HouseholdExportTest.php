<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Household export (backup/export, Phase 2 leftover): one JSON document with
 * the full locations → shelves → products tree, on both the API and the web
 * UI. Critical paths: tenancy (member-only, 404 never 403), the join code
 * staying out of the document, and the tree actually nesting.
 */
class HouseholdExportTest extends TestCase
{
    use RefreshDatabase;

    private string $api = 'http://inventory.test/api/v1';

    private string $web = 'http://inventory.test';

    /** @return array{User, Household} */
    private function memberSetup(): array
    {
        $user = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => bcrypt('secret-password')]);
        $household = Household::query()->create(['name' => 'Home Base', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return [$user, $household];
    }

    private function seedTree(Household $household): Product
    {
        $location = StorageLocation::query()->create([
            'household_id' => $household->id, 'name' => 'Freezer', 'type' => 'freezer',
        ]);
        $shelf = Shelf::query()->create(['location_id' => $location->id, 'name' => 'Top drawer']);

        return Product::query()->create([
            'shelf_id' => $shelf->id, 'name' => 'Peas', 'quantity' => 3,
            'is_mandatory' => true, 'low_stock_threshold' => 2,
        ]);
    }

    public function test_member_downloads_the_full_tree_via_the_api(): void
    {
        [$user, $household] = $this->memberSetup();
        $this->seedTree($household);
        Sanctum::actingAs($user);

        $response = $this->getJson("{$this->api}/households/{$household->id}/export")
            ->assertOk()
            ->assertHeader('Content-Disposition')
            ->assertJsonPath('format', 'inventory.household-export.v1')
            ->assertJsonPath('household.name', 'Home Base')
            ->assertJsonPath('members.0.email', 'm@example.test')
            ->assertJsonPath('locations.0.name', 'Freezer')
            ->assertJsonPath('locations.0.shelves.0.name', 'Top drawer')
            ->assertJsonPath('locations.0.shelves.0.products.0.name', 'Peas')
            ->assertJsonPath('locations.0.shelves.0.products.0.quantity', 3)
            ->assertJsonPath('locations.0.shelves.0.products.0.low_stock_threshold', 2);

        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        // The join code is a credential; an export is made to leave the household.
        $this->assertStringNotContainsString($household->join_code, $response->getContent() ?: '');
    }

    public function test_non_member_gets_404_from_the_api_export(): void
    {
        [, $household] = $this->memberSetup();
        $stranger = User::create(['name' => 'S', 'email' => 's@example.test', 'password' => bcrypt('secret-password')]);
        Sanctum::actingAs($stranger);

        $this->getJson("{$this->api}/households/{$household->id}/export")->assertNotFound();
    }

    public function test_member_downloads_the_export_via_the_web_ui(): void
    {
        [$user, $household] = $this->memberSetup();
        $this->seedTree($household);

        $response = $this->actingAs($user, 'inventory')
            ->get("{$this->web}/app/households/{$household->id}/export")
            ->assertOk()
            ->assertJsonPath('format', 'inventory.household-export.v1')
            ->assertJsonPath('locations.0.shelves.0.products.0.name', 'Peas');

        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_non_member_gets_404_from_the_web_export(): void
    {
        [, $household] = $this->memberSetup();
        $stranger = User::create(['name' => 'S', 'email' => 's@example.test', 'password' => bcrypt('secret-password')]);

        $this->actingAs($stranger, 'inventory')
            ->get("{$this->web}/app/households/{$household->id}/export")
            ->assertNotFound();
    }

    public function test_guest_is_redirected_from_the_web_export(): void
    {
        [, $household] = $this->memberSetup();

        $this->get("{$this->web}/app/households/{$household->id}/export")->assertRedirect();
    }
}
