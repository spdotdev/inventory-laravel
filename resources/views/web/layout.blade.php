<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', __('Inventory'))</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{min-height:100vh;background:#0a141d;font-family:'Plus Jakarta Sans',sans-serif;color:#eaf6ff}
  a{color:#7dd3fc;text-decoration:none}
  a:hover{text-decoration:underline}
  header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid rgba(125,211,252,.14)}
  header .brand{font-weight:700;color:#7dd3fc;font-size:17px}
  header nav{display:flex;gap:18px;align-items:center;font-size:14px}
  main{max-width:760px;margin:0 auto;padding:32px 24px}
  h1{font-size:24px;font-weight:700;color:#7dd3fc;margin-bottom:6px}
  .sub{font-size:14px;color:#7fa8c4;margin-bottom:26px;line-height:1.5}
  .card{background:rgba(125,211,252,.07);border:1px solid rgba(125,211,252,.18);border-radius:20px;padding:28px;margin-bottom:18px}
  .section{font-size:13px;letter-spacing:1.5px;text-transform:uppercase;color:#7dd3fc;opacity:.75;margin:26px 0 10px}
  label{display:block;font-size:13px;font-weight:600;color:#b8d8f0;margin-bottom:6px}
  input[type=text],input[type=email],input[type=password],input[type=number],select{width:100%;padding:12px 14px;background:rgba(125,211,252,.06);border:1px solid rgba(125,211,252,.22);border-radius:10px;color:#eaf6ff;font-size:15px;font-family:inherit;outline:none;margin-bottom:18px}
  input:focus,select:focus{border-color:#7dd3fc}
  button,.btn{display:inline-block;padding:12px 22px;background:#7dd3fc;color:#06283b;font-size:14px;font-weight:700;font-family:inherit;border:none;border-radius:12px;cursor:pointer;text-align:center}
  button:hover,.btn:hover{background:#93ddfb;text-decoration:none}
  .btn-quiet{background:transparent;color:#7dd3fc;border:1px solid rgba(125,211,252,.35)}
  .btn-quiet:hover{background:rgba(125,211,252,.08)}
  .btn-danger{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.4)}
  .btn-danger:hover{background:rgba(239,68,68,.25)}
  .or{display:flex;align-items:center;gap:10px;margin:18px 0;color:#7fa8c4;font-size:12px}
  .or::before,.or::after{content:"";flex:1;height:1px;background:rgba(125,211,252,.18)}
  .btn-google{display:flex;align-items:center;justify-content:center;gap:10px;width:100%}
  .error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);border-radius:10px;padding:12px 14px;font-size:13px;color:#fca5a5;margin-bottom:20px}
  .field-error{color:#fca5a5;font-size:12px;margin:-14px 0 16px}
  .flash{background:rgba(125,211,252,.1);border:1px solid rgba(125,211,252,.3);border-radius:10px;padding:12px 14px;font-size:13px;color:#b8e4ff;margin-bottom:20px}
  .mono{font-family:'Space Mono',monospace}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{color:#7fa8c4;font-weight:600;text-align:left;padding:10px 8px;border-bottom:1px solid rgba(125,211,252,.14)}
  td{padding:12px 8px;border-bottom:1px solid rgba(125,211,252,.08)}
  .table-scroll{overflow-x:auto}
  .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .grow{flex:1}
  .muted{color:#7fa8c4;font-size:13px}
  form.inline{display:inline}
  footer.app-promo{max-width:760px;margin:0 auto;padding:0 24px 32px;font-size:13px;color:#7fa8c4;text-align:center}
  /* Web parity Task 1: shared feedback layer (savebar + toasts). */
  .inv-savebar{position:fixed;top:0;left:0;width:100%;height:3px;z-index:60;background:transparent;opacity:0;transition:opacity .15s ease}
  .inv-savebar.is-active{opacity:1}
  .inv-savebar-fill{height:100%;width:40%;background:#7dd3fc;animation:inv-savebar-slide 1.1s ease-in-out infinite}
  @keyframes inv-savebar-slide{0%{transform:translateX(-100%)}100%{transform:translateX(250%)}}
  .inv-toast-container{position:fixed;bottom:20px;right:20px;z-index:60;display:flex;flex-direction:column;gap:10px;max-width:340px}
  .inv-toast{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,.35);animation:inv-toast-in .15s ease-out}
  .inv-toast-success{background:rgba(125,211,252,.16);border:1px solid rgba(125,211,252,.4);color:#eaf6ff}
  .inv-toast-error{background:rgba(239,68,68,.16);border:1px solid rgba(239,68,68,.45);color:#fca5a5}
  .inv-toast-retry{background:transparent;border:1px solid currentColor;color:inherit;border-radius:8px;padding:4px 10px;font-size:12px;font-weight:700;cursor:pointer;flex-shrink:0}
  @keyframes inv-toast-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
@include('inventory::web.partials.savebar')
<header>
  <a class="brand" href="{{ route('inventory.web.households') }}">Inventory</a>
  <nav>
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
