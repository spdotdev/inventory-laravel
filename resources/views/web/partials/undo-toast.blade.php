{{-- Web parity T4: upgrades the post-delete flash into an Undo toast. The
     <form> below IS the no-JS fallback (a real POST + redirect) — the inline
     script only adds a visual ~8s countdown fade on top of it; Undo works
     identically with JS disabled or failed to load, per the spec's
     progressive-enhancement rule. --}}
@if (session('undo'))
  @php $undo = session('undo'); @endphp
  <div class="inv-toast inv-toast-success" id="inv-undo-toast" style="position:fixed;bottom:20px;left:20px;z-index:60">
    <span>{{ __('Undo?') }}</span>
    <form method="POST" action="{{ route('inventory.web.restore', [$undo['household'], $undo['batch']]) }}" style="display:inline;margin:0">
      @csrf
      <button type="submit" class="inv-toast-retry">{{ __('Undo') }}</button>
    </form>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const toast = document.getElementById('inv-undo-toast');
      if (!toast) return;
      // Visual-only countdown (spec: "countdown ~8s visual"); the form above
      // remains clickable/functional the whole time and after — this only
      // fades the toast, it never disables or removes the Undo action.
      const ttlMs = 8000;
      const start = Date.now();
      toast.style.transition = 'opacity .3s linear';
      const tick = () => {
        const remaining = ttlMs - (Date.now() - start);
        if (remaining <= 0) {
          toast.style.opacity = '0.35';
          return;
        }
        toast.style.opacity = String(0.35 + 0.65 * (remaining / ttlMs));
        requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    });
  </script>
@endif
