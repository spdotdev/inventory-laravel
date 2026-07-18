<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ __('Join a household') }} — {{ __('Inventory') }}</title>
<meta name="robots" content="noindex, nofollow">
<link href="{{ asset('vendor/inventory/fonts/fonts.css') }}" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{min-height:100vh;background:radial-gradient(1200px 600px at 50% -10%, rgba(125,211,252,.22), transparent 60%),linear-gradient(160deg,#0f1f2b,#0a141d);font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:#eaf6ff;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
  .card{width:100%;max-width:440px;text-align:center;background:rgba(125,211,252,.08);border:1px solid rgba(125,211,252,.18);border-radius:24px;padding:44px 32px;backdrop-filter:blur(20px) saturate(140%);-webkit-backdrop-filter:blur(20px) saturate(140%);box-shadow:0 30px 80px rgba(0,0,0,.35)}
  .mark{width:60px;height:60px;border-radius:18px;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;background:linear-gradient(145deg,#7dd3fc,#bfe9ff);color:#06283b;font-size:28px;box-shadow:0 10px 30px rgba(125,211,252,.22)}
  h1{font-size:22px;font-weight:700;margin-bottom:8px}
  p{font-size:14px;color:#7fa8c4;line-height:1.55;margin-bottom:26px}
  .code-label{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7dd3fc;margin-bottom:8px}
  .code{font-size:28px;font-weight:800;letter-spacing:.12em;color:#eaf6ff;background:rgba(125,211,252,.10);border:1px dashed rgba(125,211,252,.35);border-radius:14px;padding:16px 12px;margin-bottom:28px;word-break:break-all}
  .btn{display:inline-block;width:100%;padding:14px;background:#7dd3fc;color:#06283b;font-size:15px;font-weight:700;font-family:inherit;border:none;border-radius:12px;cursor:pointer;text-decoration:none}
  .btn:hover{background:#93ddfb}
  .btn-web{background:transparent;color:#7dd3fc;border:1px solid rgba(125,211,252,.35);margin-top:10px}
  .btn-web:hover{background:rgba(125,211,252,.08)}
  .hint{font-size:13px;color:#4d7a9c;margin-top:22px;line-height:1.5}
  /* Light scheme (audit #25) — matches the web layout's light tokens. */
  @media (prefers-color-scheme: light){
    body{background:radial-gradient(1200px 600px at 50% -10%, rgba(34,152,186,.16), transparent 60%),linear-gradient(160deg,#cfdfeb,#c2d5e3);color:#0d2436}
    .card{background:#f5fafd;border-color:rgba(34,152,186,.25);box-shadow:0 30px 80px rgba(13,36,54,.18)}
    h1{color:#0d2436}
    p{color:#3d5a6e}
    .code-label{color:#14647d}
    .code{color:#0d2436;background:rgba(34,152,186,.08);border-color:rgba(34,152,186,.35)}
    .btn{background:#2298ba;color:#fff}
    .btn:hover{background:#1f89a8}
    .btn-web{background:transparent;color:#14647d;border-color:rgba(34,152,186,.35)}
    .btn-web:hover{background:rgba(34,152,186,.08)}
    .hint{color:#3d5a6e}
  }
</style>
</head>
<body>
<div class="card">
  <div class="mark" aria-hidden="true">📦</div>
  <h1>{{ __("You're invited to a household") }}</h1>
  <p>{{ __("Open the Inventory app and enter this join code to share the household's storage.") }}</p>

  <div class="code-label">{{ __('Join code') }}</div>
  <div class="code">{{ $code }}</div>

  @if ($appUrl !== '')
    <a class="btn" href="{{ $appUrl }}">{{ __('Get the app') }}</a>
  @endif

  {{-- The web is a first-class surface (audit #24): signed-in users land on
       the join form with the code prefilled; guests go through login first
       (redirect()->intended() preserves the query). --}}
  <a class="btn btn-web" href="{{ route('inventory.web.households', ['join' => $code]) }}">{{ __('Join on the web') }}</a>

  <p class="hint">{!! __('Already have the app? Tap :action and enter the code above.', ['action' => '<strong>'.__('Join a household').'</strong>']) !!}</p>
</div>
</body>
</html>
