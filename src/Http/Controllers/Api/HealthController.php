<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController
{
    /**
     * Liveness + dependency probe for the headless API. Confirms the package's
     * api/v1 route group is mounted AND that the database is reachable, so an
     * orchestrator (or the Android client) sees a 503 — not a misleading 200 —
     * when the app is up but its DB is not. A `SELECT 1` is the cheapest probe
     * that actually exercises the connection.
     */
    public function __invoke(): JsonResponse
    {
        $database = $this->databaseStatus();
        $healthy = $database === 'ok';

        return response()->json([
            'name' => 'inventory',
            'api' => 'v1',
            'status' => $healthy ? 'ok' : 'error',
            'database' => $database,
        ], $healthy ? 200 : 503);
    }

    private function databaseStatus(): string
    {
        try {
            DB::connection()->select('select 1');

            return 'ok';
        } catch (Throwable $e) {
            // Log for operators, but never leak connection details in the response.
            report($e);

            return 'unavailable';
        }
    }
}
