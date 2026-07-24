<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Tests\TestCase;

class AdminActivityApiTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1/admin';

    private string $token = 'super-secret-admin-token';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('inventory.admin_token', $this->token);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->getJson("{$this->base}/activity")->assertStatus(401);
    }

    public function test_lists_entries_across_households_newest_first(): void
    {
        ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => 'A', 'created_at' => now()->subMinutes(5)]);
        ActivityLogEntry::create(['household_id' => 2, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 2, 'subject_label' => 'B', 'created_at' => now()]);

        $response = $this->getJson("{$this->base}/activity", $this->auth())->assertOk();

        $this->assertSame('B', $response->json('data.0.subject_label'));
        $this->assertSame('A', $response->json('data.1.subject_label'));
    }

    public function test_filters_by_household_id(): void
    {
        ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => 'A']);
        ActivityLogEntry::create(['household_id' => 2, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 2, 'subject_label' => 'B']);

        $response = $this->getJson("{$this->base}/activity?household_id=2", $this->auth())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('B', $response->json('data.0.subject_label'));
    }

    public function test_filters_by_action(): void
    {
        ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => 'A']);
        ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.deleted', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => 'A']);

        $response = $this->getJson("{$this->base}/activity?action=household.deleted", $this->auth())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('household.deleted', $response->json('data.0.action'));
    }

    public function test_respects_per_page_and_clamps_to_one_hundred(): void
    {
        for ($i = 0; $i < 15; $i++) {
            ActivityLogEntry::create(['household_id' => 1, 'action' => 'household.created', 'subject_type' => 'Household', 'subject_id' => 1, 'subject_label' => "H{$i}"]);
        }

        $response = $this->getJson("{$this->base}/activity?per_page=10", $this->auth())->assertOk();
        $this->assertCount(10, $response->json('data'));
        $this->assertSame(10, $response->json('meta.per_page'));

        $clamped = $this->getJson("{$this->base}/activity?per_page=500", $this->auth())->assertOk();
        $this->assertSame(100, $clamped->json('meta.per_page'));
    }
}
