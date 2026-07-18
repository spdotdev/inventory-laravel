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
   * @param {object} [fetchOptions] - standard fetch() init (method, body, ...)
   * @param {object} [feedback]
   * @param {Function} [feedback.onRevert] - called (with no args) on failure,
   *   to undo the optimistic change already applied to the DOM/Alpine state.
   * @param {string} [feedback.successMessage] - defaults to a generic saved message.
   * @param {string} [feedback.errorMessage] - plain-words description of what
   *   didn't save, e.g. "Shelf order didn't save — check your connection".
   * @returns {Promise<Response>} resolves with the Response on 2xx, rejects on
   *   failure (network error or non-2xx) after handling revert + toast + retry.
   */
  function save(url, fetchOptions = {}, feedback = {}) {
    const {
      onRevert = null,
      successMessage = window.InventoryFeedback.strings.saved,
      errorMessage = window.InventoryFeedback.strings.saveFailed,
    } = feedback;

    inFlightCount += 1;
    setSaving(true);
    updateDirtyFlag();

    const headers = Object.assign(
      {
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
      fetchOptions.headers || {}
    );

    const attempt = () =>
      fetch(url, Object.assign({ credentials: 'same-origin' }, fetchOptions, { headers }));

    return attempt()
      .then((response) => {
        inFlightCount -= 1;
        if (inFlightCount === 0) setSaving(false);

        if (!response.ok) {
          hasFailedSave = true;
          updateDirtyFlag();
          if (typeof onRevert === 'function') onRevert();
          showToast(errorMessage, {
            variant: 'error',
            retry: () => {
              hasFailedSave = false;
              updateDirtyFlag();
              save(url, fetchOptions, feedback);
            },
          });
          throw new Error('inventory-feedback: request failed with status ' + response.status);
        }

        hasFailedSave = false;
        updateDirtyFlag();
        showToast(successMessage, { variant: 'success' });
        return response;
      })
      .catch((error) => {
        if (inFlightCount > 0) {
          inFlightCount -= 1;
          if (inFlightCount === 0) setSaving(false);
        }
        hasFailedSave = true;
        updateDirtyFlag();
        if (typeof onRevert === 'function') onRevert();
        showToast(errorMessage, {
          variant: 'error',
          retry: () => {
            hasFailedSave = false;
            updateDirtyFlag();
            save(url, fetchOptions, feedback);
          },
        });
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
})();
