<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Support\Restorer;

/**
 * Web twin of Api\RestoreController — same Restorer writer, same
 * `restoreBatch` gate (Owner/Admin restore any batch; a Member may restore a
 * batch they minted themselves). Two callers hit this route: the Undo button
 * on the
 * post-delete flash toast (Task 1's toast, upgraded with a countdown by
 * web-feedback.js) and the "Recently deleted" section's per-batch Restore
 * button — both are plain `<form method="POST">` submits (no Alpine fetch:
 * a restore always navigates/refreshes, same reasoning as T3's delete
 * dialogs), so the default response is redirect+flash. A JSON branch is kept
 * for completeness/any future fetch-based caller, mirroring how
 * WebLocationController::reorder/WebShelfController::reorder branch on
 * wantsJson().
 */
class WebRestoreController extends Controller
{
    public function __invoke(Request $request, Household $household, string $batch): RedirectResponse|JsonResponse
    {
        // See Api\RestoreController for the full reasoning: gate only when a
        // batch owner was actually found, so probing an unknown/already
        // restored batch id falls through to STATUS_NOTHING (409) instead of
        // leaking existence via a 403.
        $batchOwnerId = Restorer::batchOwnerId($household, $batch);

        if ($batchOwnerId !== null) {
            Gate::authorize('restoreBatch', [$household, $batchOwnerId]);
        }

        $result = Restorer::restore($household, $batch);

        $message = match ($result['status']) {
            Restorer::STATUS_NOTHING => __('Nothing to restore. This was already restored, or permanently removed.'),
            Restorer::STATUS_BLOCKED => __('Cannot restore: a parent of one of these rows is still deleted under a different batch, or restoring would create a second live Unsorted shelf. Restore the parent first.'),
            default => trans_choice(':count item restored.|:count items restored.', $result['restored'], ['count' => $result['restored']]),
        };

        if ($request->wantsJson()) {
            return response()->json(
                ['message' => $message, 'restored' => $result['restored']],
                $result['status'] === Restorer::STATUS_RESTORED ? 200 : 409,
            );
        }

        // Undo failure surfaces loudly (spec: "Feedback & error visibility") —
        // routed through withErrors so the layout's existing `.error` flash
        // box renders it, same idiom the rest of this surface uses for a
        // failed mutation (e.g. WebHouseholdController::join).
        if ($result['status'] !== Restorer::STATUS_RESTORED) {
            return back()->withErrors(['restore' => $message]);
        }

        return redirect()
            ->route('inventory.web.households.show', $household)
            ->withFragment('recently-deleted')
            ->with('status', $message);
    }
}
