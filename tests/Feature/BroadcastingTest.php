<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/** Q-3 live updates: HouseholdChanged pings + private-channel tenancy. */
class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    /** @return array{User, Household} */
    private function memberSetup(): array
    {
        $user = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => bcrypt('secret-password')]);
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return [$user, $household];
    }

    public function test_mutations_anywhere_in_the_tree_ping_the_household(): void
    {
        Event::fake([HouseholdChanged::class]);
        [, $household] = $this->memberSetup();
        Event::assertDispatched(HouseholdChanged::class); // household create itself pings

        $location = $household->locations()->create(['name' => 'Fridge', 'type' => 'fridge']);
        $shelf = $location->shelves()->create(['name' => 'Top']);
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);
        $product->update(['quantity' => 2]);
        // addStock()/removeStock() write via the query builder (no Eloquent
        // event) and dispatch this ping themselves — see Product's docblock —
        // so they count here too, same as every other tree mutation.
        $product->addStock(1, 100);
        $product->removeStock(1);

        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $household->id,
        );
        // location create + shelf create + product create + product update
        // + stock add + stock remove (+ the household create above).
        Event::assertDispatchedTimes(HouseholdChanged::class, 7);
    }

    /** @return array{User, Household, Shelf, Product} */
    private function productSetup(): array
    {
        [$user, $household] = $this->memberSetup();
        $location = $household->locations()->create(['name' => 'Fridge', 'type' => 'fridge']);
        $shelf = $location->shelves()->create(['name' => 'Top']);
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 5]);

        return [$user, $household, $shelf, $product];
    }

    /**
     * The bug this class' fix closes: addStock()/removeStock() write via the
     * query builder, which fires no Eloquent event, so BroadcastHouseholdChange
     * never saw a stock change — every OTHER member's app went stale on the
     * single most common mutation in the app. Re-fakes AFTER building the tree
     * so only the stock action's own dispatch is counted; the tree-build
     * pings are proven separately above.
     */
    public function test_stock_add_via_api_dispatches_household_changed_exactly_once(): void
    {
        Event::fake([HouseholdChanged::class]);
        [$user, $household, $shelf, $product] = $this->productSetup();
        Sanctum::actingAs($user);

        Event::fake([HouseholdChanged::class]);

        $this->postJson(
            "{$this->base}/households/{$household->id}/shelves/{$shelf->id}/products/{$product->id}/add",
            ['amount' => 3],
        )->assertOk();

        Event::assertDispatchedTimes(HouseholdChanged::class, 1);
        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $household->id,
        );
    }

    public function test_stock_remove_via_api_dispatches_household_changed_exactly_once(): void
    {
        Event::fake([HouseholdChanged::class]);
        [$user, $household, $shelf, $product] = $this->productSetup();
        Sanctum::actingAs($user);

        Event::fake([HouseholdChanged::class]);

        $this->postJson(
            "{$this->base}/households/{$household->id}/shelves/{$shelf->id}/products/{$product->id}/remove",
            ['amount' => 2],
        )->assertOk();

        Event::assertDispatchedTimes(HouseholdChanged::class, 1);
        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $household->id,
        );
    }

    public function test_stock_add_via_web_dispatches_household_changed_exactly_once(): void
    {
        Event::fake([HouseholdChanged::class]);
        [$user, $household, $shelf, $product] = $this->productSetup();

        Event::fake([HouseholdChanged::class]);

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.products.add', [$household, $shelf, $product]))
            ->assertRedirect();

        Event::assertDispatchedTimes(HouseholdChanged::class, 1);
        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $household->id,
        );
    }

    public function test_stock_remove_via_web_dispatches_household_changed_exactly_once(): void
    {
        Event::fake([HouseholdChanged::class]);
        [$user, $household, $shelf, $product] = $this->productSetup();

        Event::fake([HouseholdChanged::class]);

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.products.remove', [$household, $shelf, $product]))
            ->assertRedirect();

        Event::assertDispatchedTimes(HouseholdChanged::class, 1);
        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $household->id,
        );
    }

    /**
     * Channel registrations land on the DEFAULT driver's broadcaster instance
     * at provider boot, so the driver must be set BEFORE boot (as production's
     * BROADCAST_CONNECTION is) — switching config inside the test would leave
     * the pusher broadcaster with no channels and 403 everything.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The pusher driver (require-dev) actually runs the channel closure and
        // signs the response locally — the null/log drivers skip authorization.
        $app['config']->set('broadcasting.default', 'pusher');
        $app['config']->set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'app_id' => 'test-app',
            'options' => ['host' => 'localhost', 'port' => 6001, 'scheme' => 'http'],
        ]);
    }

    public function test_channel_auth_allows_members_and_rejects_strangers(): void
    {
        // Keep setUp's model events from actually broadcasting to the (fake)
        // pusher host — only the auth endpoint below is under test.
        Event::fake([HouseholdChanged::class]);
        [$member, $household] = $this->memberSetup();

        Sanctum::actingAs($member);
        $this->postJson("{$this->base}/broadcasting/auth", [
            'channel_name' => 'private-inventory.household.'.$household->id,
            'socket_id' => '123.456',
        ])->assertOk()->assertJsonStructure(['auth']);

        $stranger = User::create(['name' => 'S', 'email' => 's@example.test', 'password' => bcrypt('secret-password')]);
        Sanctum::actingAs($stranger);
        $this->postJson("{$this->base}/broadcasting/auth", [
            'channel_name' => 'private-inventory.household.'.$household->id,
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    public function test_channel_auth_requires_authentication(): void
    {
        Event::fake([HouseholdChanged::class]);
        [, $household] = $this->memberSetup();

        $this->postJson("{$this->base}/broadcasting/auth", [
            'channel_name' => 'private-inventory.household.'.$household->id,
            'socket_id' => '123.456',
        ])->assertUnauthorized();
    }

    /** The web UI's session-guarded twin of the api/v1 auth endpoint. */
    public function test_web_session_channel_auth_allows_members_and_rejects_strangers(): void
    {
        Event::fake([HouseholdChanged::class]);
        [$member, $household] = $this->memberSetup();

        $this->actingAs($member, 'inventory')->postJson('http://inventory.test/broadcasting/auth', [
            'channel_name' => 'private-inventory.household.'.$household->id,
            'socket_id' => '123.456',
        ])->assertOk()->assertJsonStructure(['auth']);

        $stranger = User::create(['name' => 'S', 'email' => 's@example.test', 'password' => bcrypt('secret-password')]);
        $this->actingAs($stranger, 'inventory')->postJson('http://inventory.test/broadcasting/auth', [
            'channel_name' => 'private-inventory.household.'.$household->id,
            'socket_id' => '123.456',
        ])->assertForbidden();

        auth('inventory')->logout();
        $this->postJson('http://inventory.test/broadcasting/auth', [
            'channel_name' => 'private-inventory.household.'.$household->id,
            'socket_id' => '123.456',
        ])->assertUnauthorized();
    }

    /**
     * The Blade pages embed the live-updates client only when a broadcaster
     * is configured (this class's defineEnvironment provides pusher).
     */
    public function test_household_page_embeds_the_live_updates_client(): void
    {
        Event::fake([HouseholdChanged::class]);
        [$member, $household] = $this->memberSetup();

        $this->actingAs($member, 'inventory')
            ->get('http://inventory.test/app/households/'.$household->id)
            ->assertOk()
            ->assertSee('private-inventory.household.'.$household->id, false)
            ->assertSee('/broadcasting/auth', false);
    }

    public function test_live_updates_client_is_absent_without_a_broadcaster(): void
    {
        Event::fake([HouseholdChanged::class]);
        [$member, $household] = $this->memberSetup();

        // The partial reads config at render time, so this takes effect even
        // though channel registration happened at boot.
        config()->set('broadcasting.default', 'null');

        $this->actingAs($member, 'inventory')
            ->get('http://inventory.test/app/households/'.$household->id)
            ->assertOk()
            ->assertDontSee('/broadcasting/auth', false);
    }
}
