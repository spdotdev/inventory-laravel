<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * The unauthenticated crash-intake endpoint (POST /errors) and its retention
 * prune command: it must accept valid reports, reject junk, throttle floods,
 * and prune old rows.
 */
class ClientErrorsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://inventory.test/api/v1/errors';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array'); // deterministic, isolated throttle counters
    }

    public function test_a_valid_report_is_stored(): void
    {
        $this->postJson(self::URL, [
            'device_id' => 'device-abc',
            'error_code' => 'NETWORK_TIMEOUT',
            'message' => 'Could not reach the server',
            'app_version' => '0.1.4',
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_client_errors', [
            'device_id' => 'device-abc',
            'error_code' => 'NETWORK_TIMEOUT',
        ]);
    }

    public function test_a_malformed_report_is_rejected(): void
    {
        $this->postJson(self::URL, ['message' => 'no required fields'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_id', 'error_code']);
    }

    public function test_a_single_device_is_throttled(): void
    {
        config()->set('inventory.rate_limits.errors_per_device', 3);

        $payload = ['device_id' => 'flooder', 'error_code' => 'X'];
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(self::URL, $payload)->assertCreated();
        }
        $this->postJson(self::URL, $payload)->assertStatus(429);
    }

    public function test_prune_deletes_rows_older_than_the_retention_window(): void
    {
        config()->set('inventory.client_errors_retention_days', 30);

        DB::table('inventory_client_errors')->insert([
            ['device_id' => 'd', 'error_code' => 'OLD', 'message' => null, 'app_version' => null, 'created_at' => now()->subDays(31)],
            ['device_id' => 'd', 'error_code' => 'RECENT', 'message' => null, 'app_version' => null, 'created_at' => now()->subDays(1)],
        ]);

        $this->artisan('inventory:client-errors:prune')
            ->expectsOutputToContain('Pruned 1 client-error row')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('inventory_client_errors', ['error_code' => 'OLD']);
        $this->assertDatabaseHas('inventory_client_errors', ['error_code' => 'RECENT']);
    }

    public function test_prune_is_a_noop_when_retention_is_disabled(): void
    {
        config()->set('inventory.client_errors_retention_days', 0);

        DB::table('inventory_client_errors')->insert(
            ['device_id' => 'd', 'error_code' => 'OLD', 'message' => null, 'app_version' => null, 'created_at' => now()->subYears(5)]
        );

        $this->artisan('inventory:client-errors:prune')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        $this->assertDatabaseHas('inventory_client_errors', ['error_code' => 'OLD']);
    }
}
