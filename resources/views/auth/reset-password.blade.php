<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"@if($__invDisplayMode = request()->cookie(\Spdotdev\Inventory\Http\Controllers\Web\WebDisplayModeController::COOKIE)) data-theme="{{ $__invDisplayMode === 'light' ? 'light' : 'dark' }}"@endif>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ __('Reset password') }} — {{ __('Inventory') }}</title>
<link href="{{ asset('vendor/inventory/fonts/fonts.css') }}" rel="stylesheet">
<style>
  :root{
    --bg:#0a141d;--text:#eaf6ff;--text-muted:#7fa8c4;--text-heading:#b8d8f0;
    --accent:#7dd3fc;--on-accent:#06283b;
    --card-bg:rgba(125,211,252,.07);--card-border:rgba(125,211,252,.18);
    --input-bg:rgba(125,211,252,.06);--input-border:rgba(125,211,252,.22);
    --danger-bg:rgba(239,68,68,.12);--danger-border:rgba(239,68,68,.35);--danger-text:#fca5a5;
  }
  @media (prefers-color-scheme: light){
    :root:not([data-theme]){
      --bg:#c2d5e3;--text:#0d2436;--text-muted:#3d5a6e;--text-heading:#14647d;
      --accent:#2298ba;--on-accent:#ffffff;
      --card-bg:#f5fafd;--card-border:rgba(34,152,186,.25);
      --input-bg:#ffffff;--input-border:rgba(34,152,186,.35);
      --danger-bg:rgba(220,38,38,.10);--danger-border:rgba(220,38,38,.35);--danger-text:#b91c1c;
    }
  }
  :root[data-theme="light"]{
    --bg:#c2d5e3;--text:#0d2436;--text-muted:#3d5a6e;--text-heading:#14647d;
    --accent:#2298ba;--on-accent:#ffffff;
    --card-bg:#f5fafd;--card-border:rgba(34,152,186,.25);
    --input-bg:#ffffff;--input-border:rgba(34,152,186,.35);
    --danger-bg:rgba(220,38,38,.10);--danger-border:rgba(220,38,38,.35);--danger-text:#b91c1c;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{min-height:100vh;background:var(--bg);font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);display:flex;align-items:center;justify-content:center;padding:24px}
  .card{width:100%;max-width:420px;background:var(--card-bg);border:1px solid var(--card-border);border-radius:20px;padding:40px 32px}
  h1{font-size:22px;font-weight:700;color:var(--accent);margin-bottom:8px}
  p{font-size:14px;color:var(--text-muted);margin-bottom:28px;line-height:1.5}
  .rules{margin:-8px 0 20px;font-size:13px}
  label{display:block;font-size:13px;font-weight:600;color:var(--text-heading);margin-bottom:6px}
  input[type=password]{width:100%;padding:12px 14px;background:var(--input-bg);border:1px solid var(--input-border);border-radius:10px;color:var(--text);font-size:15px;font-family:inherit;outline:none;margin-bottom:18px}
  input[type=password]:focus{border-color:var(--accent)}
  button{width:100%;padding:14px;background:var(--accent);color:var(--on-accent);font-size:15px;font-weight:700;font-family:inherit;border:none;border-radius:12px;cursor:pointer;margin-top:4px}
  button:hover{filter:brightness(1.08)}
  .error{background:var(--danger-bg);border:1px solid var(--danger-border);border-radius:10px;padding:12px 14px;font-size:13px;color:var(--danger-text);margin-bottom:20px}
</style>
</head>
<body>
<div class="card">
  <h1>{{ __('Choose a new password') }}</h1>
  <p>{{ __('Enter a new password for your Inventory account.') }}</p>

  @if ($errors->any())
    <div class="error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('inventory.reset-password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <input type="hidden" name="email" value="{{ $email }}">

    <label for="password">{{ __('New password') }}</label>
    <input type="password" id="password" name="password" minlength="8" required autofocus autocomplete="new-password">

    <label for="password_confirmation">{{ __('Confirm password') }}</label>
    <input type="password" id="password_confirmation" name="password_confirmation" minlength="8" required autocomplete="new-password">

    <p class="rules">{{ __('At least 8 characters.') }}</p>

    <button type="submit">{{ __('Reset password') }}</button>
  </form>
</div>
</body>
</html>
