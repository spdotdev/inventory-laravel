/**
 * Shared optimistic-UI feedback layer for Alpine-backed pages (web parity
 * program, Task 1). Vendored/inline — no build step, matches the no-bundler
 * posture of the rest of the web surface.
 *
 * Binding rules this implements (see
 * docs/superpowers/specs/2026-07-18-web-parity-design.md, "Feedback & error
 * visibility"):
 *   - every background save shows a saving indicator while in flight and a
 *     brief visible confirmation on success — never a silent success.
 *   - every failure visibly reverts the optimistic change (caller-supplied
 *     revert callback), shows a plain-words error toast with Retry, and
 *     never leaves stale-looking state around longer than the in-flight
 *     window.
 *   - a beforeunload guard fires while a save is in flight or after a
 *     failed (not-yet-retried) save.
 *   - an in-flight/failed "dirty" flag is exposed so the live-updates
 *     dirty check (partials/live-updates.blade.php) can treat unsent
 *     optimistic state the same as an unsaved form.
 *
 * Client-only behavior (toast timing, animated snap-back) is accepted as
 * untested by the PHPUnit suite per the spec; this file is exercised
 * manually against the demo checklist.
 */
(() => {
  'use strict';

  const TOAST_SUCCESS_MS = 2500;
  const TOAST_ERROR_MS = 8000;

  let inFlightCount = 0;
  let hasFailedSave = false;

  function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function savebarEl() {
    return document.getElementById('inv-savebar');
  }

  function toastContainerEl() {
    return document.getElementById('inv-toast-container');
  }

  function setSaving(active) {
    const bar = savebarEl();
    if (!bar) return;
    bar.classList.toggle('is-active', active);
    bar.setAttribute('aria-hidden', active ? 'false' : 'true');
  }

  function showToast(message, { variant = 'success', retry = null } = {}) {
    const container = toastContainerEl();
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = 'inv-toast inv-toast-' + variant;
    toast.setAttribute('role', 'status');

    const text = document.createElement('span');
    text.textContent = message;
    toast.appendChild(text);

    if (typeof retry === 'function') {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'inv-toast-retry';
      btn.textContent = window.InventoryFeedback.strings.retry;
      btn.addEventListener('click', () => {
        toast.remove();
        retry();
      });
      toast.appendChild(btn);
    }

    container.appendChild(toast);

    const ttl = variant === 'error' ? TOAST_ERROR_MS : TOAST_SUCCESS_MS;
    setTimeout(() => toast.remove(), ttl);
  }

  function updateDirtyFlag() {
    window.InventoryFeedback.dirty = inFlightCount > 0 || hasFailedSave;
  }

  window.addEventListener('beforeunload', (event) => {
    if (inFlightCount > 0 || hasFailedSave) {
      event.preventDefault();
      event.returnValue = '';
      return '';
    }
  });

  /**
   * Fetch wrapper for optimistic Alpine mutations.
   *
   * @param {string} url
   * @param {object|Function} [fetchOptionsInput] - standard fetch() init
   *   (method, body, ...), OR a zero-arg factory returning one. Callers whose
   *   payload can go stale between the initial attempt and a user-triggered
   *   Retry (e.g. a reorder body built from live component state) MUST pass
   *   a factory so it's re-evaluated at retry time instead of resending a
   *   snapshot captured before the failure.
   * @param {object} [feedback]
   * @param {Function} [feedback.onRevert] - called (with no args) on failure,
   *   to undo the optimistic change already applied to the DOM/Alpine state.
   * @param {Function} [feedback.onRetry] - called (with no args) instead of
   *   the default "resend the same request" behavior when Retry is clicked.
   *   Use this to re-apply the optimistic change against CURRENT state and
   *   kick off a fresh save() with a freshly computed payload — required
   *   whenever the optimistic change/body can't just be replayed verbatim
   *   (see the reorder component below). Omit to fall back to re-invoking
   *   save() with the original url/fetchOptionsInput/feedback (safe only
   *   when fetchOptionsInput is a factory or the body truly can't go stale).
   * @param {string} [feedback.successMessage] - defaults to a generic saved message.
   * @param {string} [feedback.errorMessage] - plain-words description of what
   *   didn't save, e.g. "Shelf order didn't save — check your connection".
   * @returns {Promise<Response>} resolves with the Response on 2xx, rejects on
   *   failure (network error or non-2xx) after handling revert + toast + retry
   *   exactly once (a non-2xx response and a rejected fetch() are both funneled
   *   through the same single failure handler — see `handled` below).
   */
  function save(url, fetchOptionsInput = {}, feedback = {}) {
    const {
      onRevert = null,
      onRetry = null,
      successMessage = window.InventoryFeedback.strings.saved,
      errorMessage = window.InventoryFeedback.strings.saveFailed,
    } = feedback;

    const getFetchOptions =
      typeof fetchOptionsInput === 'function' ? fetchOptionsInput : () => fetchOptionsInput;

    inFlightCount += 1;
    setSaving(true);
    updateDirtyFlag();

    const fetchOptions = getFetchOptions() || {};
    const headers = Object.assign(
      {
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
      fetchOptions.headers || {}
    );

    const attempt = () =>
      fetch(url, Object.assign({ credentials: 'same-origin' }, fetchOptions, { headers }));

    // Single shared failure handler: whichever path detects the failure
    // (the !response.ok branch below, or the outer .catch for a rejected
    // fetch()) calls this exactly once. Fires the revert, the error toast,
    // and wires Retry to either the caller's onRetry (re-apply + recompute)
    // or a plain re-save.
    function handleFailure() {
      hasFailedSave = true;
      updateDirtyFlag();
      if (typeof onRevert === 'function') onRevert();
      showToast(errorMessage, {
        variant: 'error',
        retry: () => {
          hasFailedSave = false;
          updateDirtyFlag();
          if (typeof onRetry === 'function') {
            onRetry();
          } else {
            save(url, fetchOptionsInput, feedback);
          }
        },
      });
    }

    return attempt()
      .then((response) => {
        inFlightCount -= 1;
        if (inFlightCount === 0) setSaving(false);

        if (!response.ok) {
          handleFailure();
          // Marked `handled` so the .catch below (which this throw flows
          // into) recognizes the failure was already reported and does not
          // run handleFailure() a second time.
          const error = new Error('inventory-feedback: request failed with status ' + response.status);
          error.inventoryFeedbackHandled = true;
          throw error;
        }

        hasFailedSave = false;
        updateDirtyFlag();
        showToast(successMessage, { variant: 'success' });
        return response;
      })
      .catch((error) => {
        if (error && error.inventoryFeedbackHandled) {
          throw error;
        }

        // Network-reject path (fetch() itself threw) — single-fire, never
        // handled above.
        if (inFlightCount > 0) {
          inFlightCount -= 1;
          if (inFlightCount === 0) setSaving(false);
        }
        handleFailure();
        throw error;
      });
  }

  window.InventoryFeedback = {
    dirty: false,
    save,
    strings: {
      saved: 'Saved.',
      saveFailed: "That didn't save — check your connection.",
      retry: 'Retry',
    },
  };

  /**
   * Alpine component factory for the reorder controls (web parity Task 2:
   * locations on household.blade.php, shelves on location.blade.php).
   *
   * Rows stay in the DOM exactly as Blade rendered them (name, links, counts,
   * non-JS fallback forms) — this only tracks a reactive `order` array of
   * ids and each row binds `:style="'order:' + order.indexOf(id)"` to a CSS
   * flex/grid container, so a move is a purely-visual, instant reorder
   * (the spec's "optimistic swap") with nothing to re-render.
   *
   * @param {object} config
   * @param {string} config.url - the reorder PATCH endpoint
   * @param {number[]} config.ids - the current server order (non-system ids
   *   only, for shelves — the caller excludes the system shelf up front)
   * @param {string} config.errorMessage - plain-words failure toast copy
   */
  window.inventoryReorder = function inventoryReorder(config) {
    return {
      order: config.ids.slice(),
      move(id, direction) {
        this.attemptMove(id, direction);
      },
      // Applies the (id, direction) swap to whatever `this.order` is RIGHT
      // NOW and saves it. Used both for the initial click and for Retry —
      // Retry calls this again rather than replaying a captured payload, so
      // a) a successful retry re-applies the optimistic change (the page
      // never settles on reverted-but-server-has-the-new-state), and b) the
      // body sent is always freshly computed from current state, not a
      // snapshot that another move could have made stale in the meantime.
      // If other moves happened between the failure and the retry click
      // such that this (id, direction) no longer applies (out of range),
      // this is a no-op — there is nothing stale left to retry.
      attemptMove(id, direction) {
        const i = this.order.indexOf(id);
        const j = i + direction;
        if (i === -1 || j < 0 || j >= this.order.length) return;

        const previous = this.order.slice();
        const next = this.order.slice();
        const tmp = next[i];
        next[i] = next[j];
        next[j] = tmp;
        this.order = next;

        window.InventoryFeedback.save(
          config.url,
          // Factory, not a static body: re-evaluated on retry so it reads
          // `this.order` at retry time (set by attemptMove() above, called
          // again from onRetry) rather than resending this closure's `next`.
          () => ({
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: this.order }),
          }),
          {
            onRevert: () => {
              this.order = previous;
            },
            onRetry: () => {
              this.attemptMove(id, direction);
            },
            errorMessage: config.errorMessage,
          }
        ).catch(() => {
          // save() already reverted + toasted; nothing further to do here.
          // Swallowed so this isn't reported as an unhandled rejection.
        });
      },
    };
  };
})();
