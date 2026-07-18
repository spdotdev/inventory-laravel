<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"@if($__invDisplayMode = request()->cookie(\Spdotdev\Inventory\Http\Controllers\Web\WebDisplayModeController::COOKIE)) data-theme="{{ $__invDisplayMode === 'light' ? 'light' : 'dark' }}"@endif>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ __('Password reset') }} — {{ __('Inventory') }}</title>
<link href="{{ asset('vendor/inventory/fonts/fonts.css') }}" rel="stylesheet">
<style>
  :root{
    --bg:#0a141d;--text:#eaf6ff;--text-muted:#7fa8c4;--accent:#7dd3fc;
    --card-bg:rgba(125,211,252,.07);--card-border:rgba(125,211,252,.18);
  }
  @media (prefers-color-scheme: light){
    :root:not([data-theme]){
      --bg:#c2d5e3;--text:#0d2436;--text-muted:#3d5a6e;--accent:#2298ba;
      --card-bg:#f5fafd;--card-border:rgba(34,152,186,.25);
    }
  }
  :root[data-theme="light"]{
    --bg:#c2d5e3;--text:#0d2436;--text-muted:#3d5a6e;--accent:#2298ba;
    --card-bg:#f5fafd;--card-border:rgba(34,152,186,.25);
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{min-height:100vh;background:var(--bg);font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);display:flex;align-items:center;justify-content:center;padding:24px}
  .card{width:100%;max-width:420px;background:var(--card-bg);border:1px solid var(--card-border);border-radius:20px;padding:40px 32px;text-align:center}
  .icon{font-size:40px;margin-bottom:16px}
  h1{font-size:22px;font-weight:700;color:var(--accent);margin-bottom:12px}
  p{font-size:15px;color:var(--text-muted);line-height:1.6}
  a{color:var(--accent)}
</style>
</head>
<body>
<div class="card">
  <div class="icon">✓</div>
  <h1>{{ __('Password reset') }}</h1>
  <p>{{ __('Your password has been updated. Open the Inventory app and sign in with your new password.') }}</p>
  <p style="margin-top:14px"><a href="{{ route('inventory.web.login.show') }}">{{ __('Sign in on the web') }}</a></p>
</div>
</body>
</html>
