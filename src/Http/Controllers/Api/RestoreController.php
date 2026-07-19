<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function __invoke(Request $request, Household $household, string $batch): JsonResponse
    {
        // Batch ownership looked up BEFORE authorization, but the gate is only
        // enforced when a batch owner was actually found. A null owner means
        // the batch is unknown/already restored/purged — Restorer::restore
        // below is about to find zero rows for it and return STATUS_NOTHING
        // regardless of who's asking, so gating on a batch that doesn't exist
        // would only let a Member probing random ids learn "403 means it once
        // existed, 409 means it never did" — a household-membership leak the
        // 403-vs-404 posture (see this policy's class docblock) exists to
        // avoid. A real batch owned by someone else always resolves to a
        // non-null id here and IS gated below.
        $batchOwnerId = Restorer::batchOwnerId($household, $batch);

        if ($batchOwnerId !== null) {
            Gate::authorize('restoreBatch', [$household, $batchOwnerId]);
        }

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
