<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientErrorController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id'   => ['required', 'string', 'max:64'],
            'error_code'  => ['required', 'string', 'max:64'],
            'message'     => ['nullable', 'string', 'max:1000'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        DB::table('inventory_client_errors')->insert([
            'device_id'   => $validated['device_id'],
            'error_code'  => $validated['error_code'],
            'message'     => $validated['message'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
            'created_at'  => now(),
        ]);

        return response()->json(null, 201);
    }
}
