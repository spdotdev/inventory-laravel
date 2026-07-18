<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/** Phase-2 web UI: session auth + household onboarding on the inventory domain. */
class WebUiTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test';

    private function user(string $email = 'web@example.test'): User
    {
        return User::create(['name' => 'Web', 'email' => $email, 'password' => bcrypt('secret-password')]);
    }

    public function test_register_creates_account_and_signs_in(): void
    {
        $this->post("{$this->base}/register", [
            'name' => 'Stan',
            'email' => 'stan@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $this->assertAuthenticated('inventory');
        $this->assertDatabaseHas('inventory_users', ['email' => 'stan@example.test']);
    }

    public function test_login_with_valid_credentials_reaches_households(): void
    {
        $user = $this->user();

        $this->post("{$this->base}/login", [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('inventory.web.households'));

        $this->assertAuthenticated('inventory');
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $user = $this->user();

        $this->from("{$this->base}/login")->post("{$this->base}/login", [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertRedirect("{$this->base}/login");

        $this->assertGuest('inventory');
    }

    public function test_guests_are_redirected_to_the_sign_in_page(): void
    {
        $this->get("{$this->base}/app/households")
            ->assertRedirect(route('inventory.web.login.show'));
    }

    public function test_household_create_join_and_leave_flow(): void
    {
        $owner = $this->user('owner@example.test');

        $this->actingAs($owner, 'inventory')
            ->post("{$this->base}/app/households", ['name' => 'Home'])
            ->assertRedirect();
        $household = Household::query()->firstOrFail();
        $this->assertTrue($household->users()->whereKey($owner->getKey())->exists());

        // Second user joins by code, sees the household, then leaves.
        // flushSession(): switching accounts inside one test leaves the first
        // user's AuthenticateSession password hash behind; a real browser gets
        // a fresh hash via the login controller.
        $joiner = $this->user('joiner@example.test');
        $this->flushSession();
        $this->actingAs($joiner, 'inventory')
            ->post("{$this->base}/app/households/join", ['code' => $household->join_code])
            ->assertRedirect(route('inventory.web.households.show', $household));

        $this->actingAs($joiner, 'inventory')
            ->get(route('inventory.web.households.show', $household))
            ->assertOk()
            ->assertSee('Home')
            ->assertSee($household->join_code);

        $this->actingAs($joiner, 'inventory')
            ->delete(route('inventory.web.households.leave', $household))
            ->assertRedirect(route('inventory.web.households'));
        $this->assertFalse($household->users()->whereKey($joiner->getKey())->exists());
    }

    public function test_non_members_get_404_for_a_household_page(): void
    {
        $owner = $this->user('owner@example.test');
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now()]);

        $stranger = $this->user('stranger@example.test');
        $this->actingAs($stranger, 'inventory')
            ->get(route('inventory.web.households.show', $household))
            ->assertNotFound();
    }

    public function test_web_session_does_not_authenticate_the_api(): void
    {
        $user = $this->user();

        // A browser session on the `inventory` guard must not leak into the
        // Sanctum-token-guarded API surface.
        $this->actingAs($user, 'inventory')
            ->getJson("{$this->base}/api/v1/households")
            ->assertUnauthorized();
    }

    /** Build an authenticated member + household + location + shelf for CRUD tests. */
    private function memberSetup(): array
    {
        $user = $this->user('member@example.test');
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'admin']);
        $location = $household->locations()->create(['name' => 'Fridge', 'type' => 'fridge']);
        $shelf = $location->shelves()->create(['name' => 'Top']);

        return [$user, $household, $location, $shelf];
    }

    public function test_location_and_shelf_crud_on_the_web(): void
    {
        [$user, $household] = $this->memberSetup();

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.locations.store', $household), ['name' => 'Pantry', 'type' => 'pantry'])
            ->assertRedirect(route('inventory.web.households.show', $household).'#locations');
        $this->assertDatabaseHas('inventory_storage_locations', ['name' => 'Pantry']);

        $location = $household->locations()->where('name', 'Pantry')->firstOrFail();
        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.shelves.store', [$household, $location]), ['name' => 'Shelf A'])
            ->assertRedirect(route('inventory.web.locations.show', [$household, $location]));

        $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.locations.show', [$household, $location]))
            ->assertOk()->assertSee('Shelf A');

        // T3: 'Pantry' now holds a shelf ('Shelf A'), so a strategy is
        // required — mirrors the API's DeleteLocationRequest.
        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.locations.destroy', [$household, $location]), [
                'strategy' => 'delete_contents',
            ])
            ->assertRedirect();
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_product_crud_and_stock_actions_on_the_web(): void
    {
        [$user, $household, $location, $shelf] = $this->memberSetup();

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.products.store', [$household, $shelf]), ['name' => 'Milk'])
            ->assertRedirect(route('inventory.web.locations.show', [$household, $location]));
        $product = $shelf->products()->firstOrFail();
        $this->assertSame(0, $product->quantity);

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.products.add', [$household, $shelf, $product]));
        $this->assertSame(1, $product->refresh()->quantity);

        // Remove floors at 0 (D-012) — two removes from 1 stay at 0.
        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.products.remove', [$household, $shelf, $product]));
        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.products.remove', [$household, $shelf, $product]));
        $this->assertSame(0, $product->refresh()->quantity);

        // Full edit incl. checkbox + threshold; unchecking mandatory persists.
        $this->actingAs($user, 'inventory')
            ->put(route('inventory.web.products.update', [$household, $shelf, $product]), [
                'name' => 'Whole milk',
                'code' => '871234',
                'is_mandatory' => '1',
                'low_stock_threshold' => 2,
            ])->assertRedirect();
        $product->refresh();
        $this->assertTrue($product->is_mandatory);
        $this->assertSame(2, $product->low_stock_threshold);

        $this->actingAs($user, 'inventory')
            ->put(route('inventory.web.products.update', [$household, $shelf, $product]), [
                'name' => 'Whole milk',
                'low_stock_threshold' => '',
            ])->assertRedirect();
        $product->refresh();
        $this->assertFalse($product->is_mandatory);
        $this->assertNull($product->low_stock_threshold);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.products.destroy', [$household, $shelf, $product]))
            ->assertRedirect();
        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_deleting_a_solo_product_on_the_web_mints_its_own_batch_id(): void
    {
        // Web-surface twin of the API regression guard for FINDING 1: the web
        // UI has no client to supply a batch id, so it must mint one
        // server-side too — otherwise a solo web delete is just as
        // permanently unrestorable as the pre-fix API one.
        [$user, $household, , $shelf] = $this->memberSetup();
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.products.destroy', [$household, $shelf, $product]))
            ->assertRedirect();

        // Product uses SoftDeletes, whose global scope excludes trashed rows
        // — a plain refresh()/find() would throw ModelNotFoundException now
        // that the row is soft-deleted, so reach it via withTrashed().
        $batch = Product::withTrashed()->findOrFail($product->id)->deletion_batch_id;
        $this->assertNotNull($batch);
        $this->assertNotSame('', $batch);
    }

    public function test_the_unsorted_shelf_cannot_be_deleted_via_the_web_while_occupied(): void
    {
        // Regression guard for FINDING 2 (Task 6b review): the API refuses to
        // delete an occupied Unsorted shelf (UnsortedShelfTest covers that),
        // but the web path had no equivalent guard — an easy way to strand
        // the very products the shelf exists to protect.
        //
        // T3 update: destroy() now validates through the shared
        // DeleteShelfRequest (same Form Request the API uses), which — like
        // the API — requires a `strategy` for ANY occupied shelf before the
        // controller's is_system-specific guard is ever reached. This
        // bodyless request 422s on 'strategy', exactly like the API's own
        // UnsortedShelfTest::test_the_unsorted_shelf_cannot_be_deleted_while_occupied
        // (which only pins the 422 status for the same reason). The
        // 'shelf' field-specific message stays as defence in depth for a
        // caller that DOES supply a strategy (move_products/unsort_products)
        // against an occupied Unsorted shelf — never reachable from this UI,
        // which never renders a delete control for it (see the next test).
        [$user, $household, $location] = $this->memberSetup();
        $unsorted = $location->unsortedShelf();
        $unsorted->products()->create(['name' => 'Orphan peas', 'quantity' => 1]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.shelves.destroy', [$household, $location, $unsorted]))
            ->assertRedirect()
            ->assertSessionHasErrors('strategy');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $unsorted->id]);
    }

    public function test_the_unsorted_shelf_delete_button_is_not_rendered_on_the_web(): void
    {
        // The button was rendered unconditionally for system shelves; a click
        // would hit the exact guard the previous test proves now exists, but
        // the button should not even be offered.
        $user = $this->user('unsorted-view@example.test');
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-3333']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Fridge', 'type' => 'fridge']);
        $location->unsortedShelf();

        $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.locations.show', [$household, $location]))
            ->assertOk()
            ->assertSee('Unsorted')
            ->assertDontSee('Delete shelf');
    }

    public function test_shelf_delete_with_unsort_products_strategy_keeps_products_live_on_unsorted(): void
    {
        // H5: web shelf delete now offers a strategy picker for a non-empty
        // shelf. unsort_products must move the products to the location's
        // Unsorted shelf, not destroy them.
        [$user, $household, $location, $shelf] = $this->memberSetup();
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.shelves.destroy', [$household, $location, $shelf]), [
                'strategy' => 'unsort_products',
            ])
            ->assertRedirect();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $product->refresh();
        $this->assertNull($product->deleted_at);
        $this->assertSame($location->unsortedShelf()->id, $product->shelf_id);
    }

    public function test_shelf_delete_with_delete_products_strategy_soft_deletes_products(): void
    {
        // H5: delete_products keeps the historical "delete what's on it"
        // behavior, but now as an explicit choice rather than a hardcoded one.
        [$user, $household, $location, $shelf] = $this->memberSetup();
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.shelves.destroy', [$household, $location, $shelf]), [
                'strategy' => 'delete_products',
            ])
            ->assertRedirect();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_shelf_delete_with_unknown_strategy_is_rejected(): void
    {
        // H5: the server never guesses — an unrecognized strategy value must
        // be rejected, not silently treated as any particular default.
        [$user, $household, $location, $shelf] = $this->memberSetup();
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.shelves.destroy', [$household, $location, $shelf]), [
                'strategy' => 'nonsense_value',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('strategy');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_empty_shelf_delete_with_no_strategy_still_works(): void
    {
        // H5 backward compatibility: an empty shelf needs no strategy at all
        // — the pre-existing plain-delete path must keep working unchanged.
        [$user, $household, $location, $shelf] = $this->memberSetup();

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.shelves.destroy', [$household, $location, $shelf]))
            ->assertRedirect();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
    }

    public function test_location_delete_with_unknown_strategy_is_rejected(): void
    {
        [$user, $household, $location, $shelf] = $this->memberSetup();
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.locations.destroy', [$household, $location]), [
                'strategy' => 'nonsense_value',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('strategy');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_location_delete_with_delete_contents_strategy_still_soft_deletes_shelves_and_products(): void
    {
        // H5 backward compatibility: delete_contents (the only strategy in
        // scope for the location form) must keep destroying shelves+products
        // exactly like the old hardcoded path did.
        [$user, $household, $location, $shelf] = $this->memberSetup();
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.locations.destroy', [$household, $location]), [
                'strategy' => 'delete_contents',
            ])
            ->assertRedirect();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_web_crud_is_tenancy_gated(): void
    {
        [, $household, $location, $shelf] = $this->memberSetup();
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        $stranger = $this->user('stranger2@example.test');
        $this->actingAs($stranger, 'inventory')
            ->get(route('inventory.web.locations.show', [$household, $location]))
            ->assertNotFound();
        $this->actingAs($stranger, 'inventory')
            ->post(route('inventory.web.products.add', [$household, $shelf, $product]))
            ->assertNotFound();
        $this->assertSame(1, $product->refresh()->quantity);
    }

    public function test_names_with_quotes_cannot_break_out_of_confirm_dialogs(): void
    {
        // Regression (security review): names are interpolated into onsubmit
        // JS strings; HTML entity-encoding alone is NOT enough there because the
        // browser decodes entities before the JS engine parses the attribute.
        [$user, $household, $location, $shelf] = $this->memberSetup();
        $payload = "x'); document.title='pwned'; //";
        $shelf->products()->create(['name' => $payload, 'quantity' => 1]);

        $html = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.locations.show', [$household, $location]))
            ->assertOk()
            ->getContent();

        // Js::from hex-escapes quotes, so the raw breakout sequence must not
        // appear inside any onsubmit attribute.
        foreach (explode('onsubmit=', $html) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            $attr = substr($chunk, 0, strpos($chunk, '>') ?: null);
            $this->assertStringNotContainsString("');", $attr, 'JS-string breakout in onsubmit');
        }
    }

    public function test_household_theme_can_be_set_from_the_web(): void
    {
        [$user, $household] = $this->memberSetup();

        $this->actingAs($user, 'inventory')
            ->put(route('inventory.web.households.update', $household), ['color' => 'amber', 'icon' => 'warehouse'])
            ->assertRedirect(route('inventory.web.households.show', $household));
        $household->refresh();
        $this->assertSame('amber', $household->color);
        $this->assertSame('warehouse', $household->icon);

        // '' from the "Default" option clears (ConvertEmptyStringsToNull → null).
        $this->actingAs($user, 'inventory')
            ->put(route('inventory.web.households.update', $household), ['color' => '', 'icon' => ''])
            ->assertRedirect();
        $household->refresh();
        $this->assertNull($household->color);
        $this->assertNull($household->icon);
    }

    public function test_web_search_finds_products_and_links_their_location(): void
    {
        [$user, $household, $location, $shelf] = $this->memberSetup();
        $shelf->products()->create(['name' => 'Whole milk', 'quantity' => 3]);
        $shelf->products()->create(['name' => 'Butter', 'quantity' => 1]);

        $response = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.search', $household).'?q=milk')
            ->assertOk()
            ->assertSee('Whole milk')
            ->assertDontSee('Butter');

        // Results link into the location page (the web twin of the API's
        // nav-ID payload).
        $response->assertSee(route('inventory.web.locations.show', [$household, $location]), false);
    }

    public function test_web_search_is_tenancy_scoped_and_matches_wildcards_literally(): void
    {
        [$user, $household, , $shelf] = $this->memberSetup();
        $shelf->products()->create(['name' => '50% yoghurt', 'quantity' => 1]);
        $shelf->products()->create(['name' => '50 grams flour', 'quantity' => 1]);

        // A literal % must not act as a wildcard (same rule as the API, W11).
        $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.search', $household).'?q=50%25')
            ->assertOk()
            ->assertSee('50% yoghurt')
            ->assertDontSee('50 grams flour');

        // Non-members get a 404, never a 403 (tenancy rule).
        $stranger = $this->user('stranger3@example.test');
        $this->flushSession(); // see the join/leave flow test
        $this->actingAs($stranger, 'inventory')
            ->get(route('inventory.web.search', $household).'?q=milk')
            ->assertNotFound();
    }

    public function test_household_page_renders_the_invite_qr(): void
    {
        [$user, $household] = $this->memberSetup();

        $html = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.households.show', $household))
            ->assertOk()
            ->getContent();

        // Inline SVG QR present, and no stray XML declaration inside the HTML.
        $this->assertStringContainsString('<svg xmlns', $html);
        $this->assertStringNotContainsString('<?xml', $html);
    }
}
