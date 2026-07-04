<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * The health endpoint must reflect *dependency* health, not just liveness. DB is
 * mocked (not RefreshDatabase) so both the healthy and DB-down paths are exercised
 * without a real driver — the failure path especially can't be produced with a
 * working test DB.
 */
class HealthCheckTest extends TestCase
{
    public function test_reports_ok_and_200_when_the_database_answers(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('select')->with('select 1')->andReturn([]);
        DB::shouldReceive('connection')->andReturn($connection);

        $this->getJson('http://inventory.test/api/v1/health')
            ->assertOk()
            ->assertJson([
                'name' => 'inventory',
                'api' => 'v1',
                'status' => 'ok',
                'database' => 'ok',
            ]);
    }

    public function test_reports_error_and_503_when_the_database_is_unreachable(): void
    {
        DB::shouldReceive('connection')->andThrow(new PDOException('SQLSTATE[HY000] connection refused'));

        $this->getJson('http://inventory.test/api/v1/health')
            ->assertStatus(503)
            ->assertJson([
                'status' => 'error',
                'database' => 'unavailable',
            ])
            // The raw DB error must not leak into the probe response.
            ->assertJsonMissing(['message' => 'SQLSTATE[HY000] connection refused']);
    }
}
