<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\AppNotification;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function makeUser(string $email = 'stan@example.test'): User
    {
        return User::query()->create([
            'name' => 'Stan',
            'email' => $email,
            'password' => bcrypt('secret123'),
        ]);
    }

    public function test_returns_own_rows_ascending_with_correct_shape_and_last_id(): void
    {
        $user = $this->makeUser();
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $household->users()->attach($user);

        $first = AppNotification::query()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'type' => 'member_joined',
            'payload' => ['name' => 'Joiner'],
        ]);
        $second = AppNotification::query()->create([
            'user_id' => $user->id,
            'household_id' => null,
            'type' => 'other_type',
            'payload' => ['foo' => 'bar'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("{$this->base}/notifications")
            ->assertOk();

        $response->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.type', 'member_joined')
            ->assertJsonPath('data.0.household.id', $household->id)
            ->assertJsonPath('data.0.household.name', 'Home')
            ->assertJsonPath('data.0.payload.name', 'Joiner')
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.household', null)
            ->assertJsonPath('meta.last_id', $second->id);

        $this->assertNotNull($response->json('data.0.created_at'));
    }

    public function test_after_cursor_excludes_rows_at_or_below_it(): void
    {
        $user = $this->makeUser();

        $first = AppNotification::query()->create([
            'user_id' => $user->id,
            'household_id' => null,
            'type' => 'type_a',
            'payload' => null,
        ]);
        $second = AppNotification::query()->create([
            'user_id' => $user->id,
            'household_id' => null,
            'type' => 'type_b',
            'payload' => null,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/notifications?after={$first->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('meta.last_id', $second->id);
    }

    public function test_never_returns_another_users_rows_even_in_a_shared_household(): void
    {
        $user = $this->makeUser('stan@example.test');
        $otherUser = $this->makeUser('alex@example.test');

        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $household->users()->attach($user);
        $household->users()->attach($otherUser);

        AppNotification::query()->create([
            'user_id' => $otherUser->id,
            'household_id' => $household->id,
            'type' => 'member_joined',
            'payload' => null,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/notifications")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.last_id', 0);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson("{$this->base}/notifications")->assertStatus(401);
    }

    public function test_page_caps_at_fifty(): void
    {
        $user = $this->makeUser();

        $ids = [];
        for ($i = 0; $i < 55; $i++) {
            $ids[] = AppNotification::query()->create([
                'user_id' => $user->id,
                'household_id' => null,
                'type' => 'type_a',
                'payload' => null,
            ])->id;
        }

        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/notifications")
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.last_id', $ids[49]);
    }

    public function test_empty_page_echoes_after_back_as_last_id(): void
    {
        $user = $this->makeUser();

        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/notifications?after=42")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.last_id', 42);
    }
}
