<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
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

        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $household->id,
        );
        // location create + shelf create + product create + product update
        // (+ the household create above).
        Event::assertDispatchedTimes(HouseholdChanged::class, 5);
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
}
