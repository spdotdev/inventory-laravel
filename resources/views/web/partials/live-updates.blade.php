{{-- Live updates (Q-3) on the web: a minimal Pusher-protocol client (no JS
     dependencies, mirroring the no-build posture of these views). Subscribes
     to the household's private channel via the session-authenticated
     /broadcasting/auth route and reloads on the coarse `household.changed`
     ping — the web twin of the Android client's re-fetch. Renders nothing
     unless the host has a real broadcaster configured, matching the server
     side's graceful no-op without Reverb. --}}
@php
    $liveConnection = (string) config('broadcasting.default');
    $liveKey = in_array($liveConnection, ['reverb', 'pusher'], true)
        ? (string) config('broadcasting.connections.'.$liveConnection.'.key')
        : '';
@endphp
@if ($liveKey !== '')
<script>
(() => {
  'use strict';
  const key = {{ Illuminate\Support\Js::from($liveKey) }};
  const channel = {{ Illuminate\Support\Js::from('private-inventory.household.'.$household->id) }};
  const csrf = {{ Illuminate\Support\Js::from(csrf_token()) }};
  let reloadTimer = null;
  let attempts = 0;
  let dead = false; // set when channel auth is refused — do not reconnect

  function connect() {
    const ws = new WebSocket(
      (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host +
      '/app/' + key + '?protocol=7&client=inventory-web&version=1.0'
    );

    ws.addEventListener('message', async (event) => {
      const message = JSON.parse(event.data);

      if (message.event === 'pusher:connection_established') {
        attempts = 0;
        const socketId = JSON.parse(message.data).socket_id;
        const response = await fetch('/broadcasting/auth', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
          body: JSON.stringify({channel_name: channel, socket_id: socketId}),
        });
        if (!response.ok) { // signed out or no longer a member — stop for good
          dead = true;
          ws.close();
          return;
        }
        const {auth} = await response.json();
        ws.send(JSON.stringify({event: 'pusher:subscribe', data: {channel: channel, auth: auth}}));
      } else if (message.event === 'pusher:ping') {
        ws.send(JSON.stringify({event: 'pusher:pong', data: {}}));
      } else if (message.event === 'household.changed' && message.channel === channel) {
        // Debounced full reload: these pages are thin server-rendered views,
        // so re-rendering IS the re-fetch. The ping carries no state. GAP-6
        // M5: a reload mid-form silently threw away whatever the user typed
        // (and their scroll position). While a form is dirty, skip the
        // reload and show a small non-blocking hint instead — never destroy
        // input. Untouched pages keep the original silent-refresh behavior.
        clearTimeout(reloadTimer);
        reloadTimer = setTimeout(() => {
          if (dirty) {
            showStaleHint();
          } else {
            location.reload();
          }
        }, 500);
      }
    });

    ws.addEventListener('close', () => {
      if (dead) return;
      attempts += 1;
      setTimeout(connect, Math.min(30, 2 ** attempts) * 1000);
    });
  }

  // Dirtiness tracking: any input inside a form marks the page dirty until
  // that form is submitted (native form submits, incl. the confirm()-gated
  // ones already on these pages, still fire 'submit').
  let dirty = false;
  let hintShown = false;

  document.addEventListener('input', (event) => {
    if (event.target instanceof Element && event.target.closest('form')) {
      dirty = true;
    }
  });
  document.addEventListener('submit', () => {
    dirty = false;
  });

  function showStaleHint() {
    if (hintShown) return;
    hintShown = true;
    const hint = document.createElement('div');
    hint.className = 'flash';
    hint.style.position = 'sticky';
    hint.style.top = '0';
    hint.style.zIndex = '10';
    hint.textContent = {{ Illuminate\Support\Js::from(__('Household updated elsewhere — refresh to see changes.')) }};
    document.body.prepend(hint);
  }

  connect();
})();
</script>
@endif
