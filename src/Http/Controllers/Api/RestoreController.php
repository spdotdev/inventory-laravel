<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Support\Restorer;

/**
 * Undo one deletion gesture. Thin: all batch-scoping/blocking logic lives in
 * Support\Restorer, shared with the web parity twin
 * (Http\Controllers\Web\WebRestoreController) so the two surfaces can never
 * drift — see that class's docblock for the full C-1/C-2 reasoning.
 */
class RestoreController
{
    public function __invoke(Household $household, string $batch): JsonResponse
    {
        Gate::authorize('restructure', $household);

        $result = Restorer::restore($household, $batch);

        return match ($result['status']) {
            Restorer::STATUS_NOTHING => response()->json([
                'message' => 'Nothing to restore. This was already restored, or permanently removed.',
            ], 409),
            Restorer::STATUS_BLOCKED => response()->json([
                'message' => 'Cannot restore: a parent of one of these rows is still deleted under a different batch, or restoring would create a second live Unsorted shelf. Restore the parent first.',
            ], 409),
            default => response()->json([
                'message' => 'Restored.',
                'restored' => $result['restored'],
            ]),
        };
    }
}
