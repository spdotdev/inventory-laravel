<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"@if($__invDisplayMode = request()->cookie(\Spdotdev\Inventory\Http\Controllers\Web\WebDisplayModeController::COOKIE)) data-theme="{{ $__invDisplayMode === 'light' ? 'light' : 'dark' }}"@endif>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', __('Inventory'))</title>
<link href="{{ asset('vendor/inventory/fonts/fonts.css') }}" rel="stylesheet">
<style>
  /* Web parity T6: dark tokens are the original values (default + explicit
     data-theme="dark"); light tokens match the Android app's Frost light
     palette (ui/theme/Color.kt: FrostLightPrimary/Background/Surface/OnSurface)
     — steel-blue ground, teal-blue primary, near-white opaque cards, dark
     blue-grey text. `prefers-color-scheme` picks a default when no cookie
     has been set yet; `[data-theme]` (stamped server-side from the cookie by
     the layout's <html> tag above) always wins once the user has toggled, so
     there is never a flash of the wrong palette on first paint. */
  :root{
    --bg:#0a141d;--text:#eaf6ff;--text-muted:#7fa8c4;--text-heading:#b8d8f0;
    --accent:#7dd3fc;--on-accent:#06283b;
    --card-bg:rgba(125,211,252,.07);--card-border:rgba(125,211,252,.18);
    --input-bg:rgba(125,211,252,.06);--input-border:rgba(125,211,252,.22);
    --border:rgba(125,211,252,.14);
    --danger-bg:rgba(239,68,68,.15);--danger-border:rgba(239,68,68,.4);--danger-text:#fca5a5;--danger-heading:#f0b8b8;
    --warning-text:#fbbf24;
    --flash-bg:rgba(125,211,252,.1);--flash-border:rgba(125,211,252,.3);--flash-text:#b8e4ff;
    --toast-success-bg:rgba(125,211,252,.16);--toast-success-border:rgba(125,211,252,.4);
    --toast-error-bg:rgba(239,68,68,.16);--toast-error-border:rgba(239,68,68,.45);
    --shadow:rgba(0,0,0,.35);--dialog-backdrop:rgba(3,10,16,.72);
  }
  @media (prefers-color-scheme: light){
    :root:not([data-theme]){
      --bg:#c2d5e3;--text:#0d2436;--text-muted:#3d5a6e;--text-heading:#14647d;
      --accent:#2298ba;--on-accent:#ffffff;
      --card-bg:#f5fafd;--card-border:rgba(34,152,186,.25);
      --input-bg:#ffffff;--input-border:rgba(34,152,186,.35);
      --border:rgba(34,152,186,.25);
      --danger-bg:rgba(220,38,38,.10);--danger-border:rgba(220,38,38,.35);--danger-text:#b91c1c;--danger-heading:#b91c1c;
      --warning-text:#92620a;
      --flash-bg:rgba(34,152,186,.10);--flash-border:rgba(34,152,186,.30);--flash-text:#0f4c5c;
      --toast-success-bg:rgba(34,152,186,.14);--toast-success-border:rgba(34,152,186,.4);
      --toast-error-bg:rgba(220,38,38,.12);--toast-error-border:rgba(220,38,38,.4);
      --shadow:rgba(13,36,54,.18);--dialog-backdrop:rgba(194,213,227,.75);
    }
  }
  :root[data-theme="light"]{
    --bg:#c2d5e3;--text:#0d2436;--text-muted:#3d5a6e;--text-heading:#14647d;
    --accent:#2298ba;--on-accent:#ffffff;
    --card-bg:#f5fafd;--card-border:rgba(34,152,186,.25);
    --input-bg:#ffffff;--input-border:rgba(34,152,186,.35);
    --border:rgba(34,152,186,.25);
    --danger-bg:rgba(220,38,38,.10);--danger-border:rgba(220,38,38,.35);--danger-text:#b91c1c;--danger-heading:#b91c1c;
    --warning-text:#92620a;
    --flash-bg:rgba(34,152,186,.10);--flash-border:rgba(34,152,186,.30);--flash-text:#0f4c5c;
    --toast-success-bg:rgba(34,152,186,.14);--toast-success-border:rgba(34,152,186,.4);
    --toast-error-bg:rgba(220,38,38,.12);--toast-error-border:rgba(220,38,38,.4);
    --shadow:rgba(13,36,54,.18);--dialog-backdrop:rgba(194,213,227,.75);
  }
  :root[data-theme="dark"]{
    --bg:#0a141d;--text:#eaf6ff;--text-muted:#7fa8c4;--text-heading:#b8d8f0;
    --accent:#7dd3fc;--on-accent:#06283b;
    --card-bg:rgba(125,211,252,.07);--card-border:rgba(125,211,252,.18);
    --input-bg:rgba(125,211,252,.06);--input-border:rgba(125,211,252,.22);
    --border:rgba(125,211,252,.14);
    --danger-bg:rgba(239,68,68,.15);--danger-border:rgba(239,68,68,.4);--danger-text:#fca5a5;--danger-heading:#f0b8b8;
    --warning-text:#fbbf24;
    --flash-bg:rgba(125,211,252,.1);--flash-border:rgba(125,211,252,.3);--flash-text:#b8e4ff;
    --toast-success-bg:rgba(125,211,252,.16);--toast-success-border:rgba(125,211,252,.4);
    --toast-error-bg:rgba(239,68,68,.16);--toast-error-border:rgba(239,68,68,.45);
    --shadow:rgba(0,0,0,.35);--dialog-backdrop:rgba(3,10,16,.72);
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{min-height:100vh;background:var(--bg);font-family:'Plus Jakarta Sans',sans-serif;color:var(--text)}
  a{color:var(--accent);text-decoration:none}
  a:hover{text-decoration:underline}
  header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid var(--border)}
  header .brand{font-weight:700;color:var(--accent);font-size:17px}
  header nav{display:flex;gap:18px;align-items:center;font-size:14px}
  main{max-width:760px;margin:0 auto;padding:32px 24px}
  h1{font-size:24px;font-weight:700;color:var(--accent);margin-bottom:6px}
  .sub{font-size:14px;color:var(--text-muted);margin-bottom:26px;line-height:1.5}
  .card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:20px;padding:28px;margin-bottom:18px}
  .section{font-size:13px;letter-spacing:1.5px;text-transform:uppercase;color:var(--accent);opacity:.75;margin:26px 0 10px}
  label{display:block;font-size:13px;font-weight:600;color:var(--text-heading);margin-bottom:6px}
  input[type=text],input[type=email],input[type=password],input[type=number],select{width:100%;padding:12px 14px;background:var(--input-bg);border:1px solid var(--input-border);border-radius:10px;color:var(--text);font-size:15px;font-family:inherit;outline:none;margin-bottom:18px}
  input:focus,select:focus{border-color:var(--accent)}
  button,.btn{display:inline-block;padding:12px 22px;background:var(--accent);color:var(--on-accent);font-size:14px;font-weight:700;font-family:inherit;border:none;border-radius:12px;cursor:pointer;text-align:center}
  button:hover,.btn:hover{filter:brightness(1.08);text-decoration:none}
  .btn-quiet{background:transparent;color:var(--accent);border:1px solid var(--input-border)}
  .btn-quiet:hover{background:var(--card-bg)}
  .btn-danger{background:var(--danger-bg);color:var(--danger-text);border:1px solid var(--danger-border)}
  .btn-danger:hover{filter:brightness(1.12)}
  .or{display:flex;align-items:center;gap:10px;margin:18px 0;color:var(--text-muted);font-size:12px}
  .or::before,.or::after{content:"";flex:1;height:1px;background:var(--card-border)}
  .btn-google{display:flex;align-items:center;justify-content:center;gap:10px;width:100%}
  .error{background:var(--danger-bg);border:1px solid var(--danger-border);border-radius:10px;padding:12px 14px;font-size:13px;color:var(--danger-text);margin-bottom:20px}
  .field-error{color:var(--danger-text);font-size:12px;margin:-14px 0 16px}
  .flash{background:var(--flash-bg);border:1px solid var(--flash-border);border-radius:10px;padding:12px 14px;font-size:13px;color:var(--flash-text);margin-bottom:20px}
  .mono{font-family:'Space Mono',monospace}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{color:var(--text-muted);font-weight:600;text-align:left;padding:10px 8px;border-bottom:1px solid var(--border)}
  td{padding:12px 8px;border-bottom:1px solid var(--card-border)}
  .table-scroll{overflow-x:auto}
  .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .grow{flex:1}
  .muted{color:var(--text-muted);font-size:13px}
  form.inline{display:inline}
  footer.app-promo{max-width:760px;margin:0 auto;padding:0 24px 32px;font-size:13px;color:var(--text-muted);text-align:center}
  /* Web parity Task 1: shared feedback layer (savebar + toasts). */
  .inv-savebar{position:fixed;top:0;left:0;width:100%;height:3px;z-index:60;background:transparent;opacity:0;transition:opacity .15s ease}
  .inv-savebar.is-active{opacity:1}
  .inv-savebar-fill{height:100%;width:40%;background:var(--accent);animation:inv-savebar-slide 1.1s ease-in-out infinite}
  @keyframes inv-savebar-slide{0%{transform:translateX(-100%)}100%{transform:translateX(250%)}}
  .inv-toast-container{position:fixed;bottom:20px;right:20px;z-index:60;display:flex;flex-direction:column;gap:10px;max-width:340px}
  .inv-toast{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;font-size:13px;box-shadow:0 8px 24px var(--shadow);animation:inv-toast-in .15s ease-out}
  .inv-toast-success{background:var(--toast-success-bg);border:1px solid var(--toast-success-border);color:var(--text)}
  .inv-toast-error{background:var(--toast-error-bg);border:1px solid var(--toast-error-border);color:var(--danger-text)}
  .inv-toast-retry{background:transparent;border:1px solid currentColor;color:inherit;border-radius:8px;padding:4px 10px;font-size:12px;font-weight:700;cursor:pointer;flex-shrink:0}
  @keyframes inv-toast-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
  /* Alpine hides [x-cloak] itself only once it has finished initializing that
     element; before that (network-slow / script-blocked), this rule is what
     actually prevents a flash of pre-Alpine markup — Task 2's reorder
     buttons already relied on x-cloak but this rule was missing until T3. */
  [x-cloak]{display:none !important}
  /* Web parity T3: delete-strategy dialog (shared by location.blade.php +
     household.blade.php). Alpine-only — the <noscript> fallback forms
     alongside it need none of this. */
  .inv-dialog-backdrop{position:fixed;inset:0;background:var(--dialog-backdrop);display:flex;align-items:center;justify-content:center;z-index:70;padding:20px}
  .inv-dialog{max-width:420px;width:100%;margin-bottom:0}
  .inv-dialog-option{display:flex;align-items:flex-start;gap:8px;font-weight:400;margin-bottom:10px;cursor:pointer;font-size:14px;color:var(--text)}
  .inv-dialog-option input{margin-top:3px}
  /* Web parity T6: light/dark toggle button in the header. */
  .inv-mode-toggle{background:transparent;color:var(--text);border:1px solid var(--input-border);border-radius:10px;padding:6px 10px;font-size:14px;line-height:1}
  .inv-mode-toggle:hover{background:var(--card-bg)}
</style>
</head>
<body>
@include('inventory::web.partials.savebar')
<header>
  <a class="brand" href="{{ route('inventory.web.households') }}">Inventory</a>
  <nav>
    {{-- Web parity T6: light/dark toggle. A real form POST (not fetch) —
         see WebDisplayModeController's docblock for why this one endpoint
         skips the Task 1 optimistic-fetch pattern. --}}
    <form class="inline" method="POST" action="{{ route('inventory.web.display-mode') }}">
      @csrf
      <input type="hidden" name="mode" value="{{ ($__invDisplayMode ?? null) === 'light' ? 'dark' : 'light' }}">
      <button type="submit" class="inv-mode-toggle" aria-label="{{ __('Switch to :mode mode', ['mode' => ($__invDisplayMode ?? null) === 'light' ? __('dark') : __('light')]) }}">{{ ($__invDisplayMode ?? null) === 'light' ? '🌙' : '☀️' }}</button>
    </form>
    {{-- Web parity T7: EN/NL toggle, same plain-POST mechanism as the
         display-mode toggle above. --}}
    <form class="inline" method="POST" action="{{ route('inventory.web.locale') }}">
      @csrf
      <input type="hidden" name="locale" value="{{ app()->getLocale() === 'nl' ? 'en' : 'nl' }}">
      <button type="submit" class="inv-mode-toggle" aria-label="{{ __('Switch language') }}">{{ app()->getLocale() === 'nl' ? 'EN' : 'NL' }}</button>
    </form>
    @auth('inventory')
      <span class="muted">{{ auth('inventory')->user()->name }}</span>
      <form class="inline" method="POST" action="{{ route('inventory.web.logout') }}">
        @csrf
        <button type="submit" class="btn-quiet">{{ __('Sign out') }}</button>
      </form>
    @else
      <a href="{{ route('inventory.web.login.show') }}">{{ __('Sign in') }}</a>
    @endauth
  </nav>
</header>
<main>
  @if (session('status'))
    <div class="flash">{{ session('status') }}</div>
  @endif
  @if ($errors->any())
    <div class="error">{{ $errors->first() }}</div>
  @endif
  @yield('content')
</main>
{{-- L7 (GAP-4): cross-promote the Android app on session-guarded /app pages
     only — never on the public landing page, which has its own view and
     never extends this layout. Hidden entirely when unconfigured. --}}
@auth('inventory')
  @if (config('inventory.android_app_url'))
    <footer class="app-promo">
      {!! __('Inventory is best in the :link.', ['link' => '<a href="'.e(config('inventory.android_app_url')).'">'.__('Android app').'</a>']) !!}
    </footer>
  @endif
@endauth
@include('inventory::web.partials.toast')
@include('inventory::web.partials.undo-toast')
{{-- Web parity Task 1: Alpine.js (self-hosted, no CDN — see
     public/js/README.md for the pinned version + sha256) and the shared
     feedback layer. Both are plain progressive-enhancement scripts; pages
     render and their non-JS form fallbacks work with either script
     missing/failing to load. --}}
<script src="{{ asset('vendor/inventory/js/web-feedback.js') }}" defer></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    if (!window.InventoryFeedback) return;
    window.InventoryFeedback.strings = {
      saved: {{ Illuminate\Support\Js::from(__('Saved.')) }},
      saveFailed: {{ Illuminate\Support\Js::from(__("That didn't save — check your connection.")) }},
      retry: {{ Illuminate\Support\Js::from(__('Retry')) }},
    };
  });
</script>
<script src="{{ asset('vendor/inventory/js/alpine.min.js') }}" defer></script>
</body>
</html>
