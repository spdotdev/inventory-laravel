<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

class HealthController
{
    /**
     * Liveness/version probe for the headless API. Confirms the package's
     * api/v1 route group is mounted; the Android client can use it to verify
     * connectivity and the API version it's talking to.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => 'inventory',
            'api' => 'v1',
            'status' => 'ok',
        ]);
    }
}
