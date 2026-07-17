<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>@yield('title', 'Inventory')</title>
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
  .flash{background:rgba(125,211,252,.1);border:1px solid rgba(125,211,252,.3);border-radius:10px;padding:12px 14px;font-size:13px;color:#b8e4ff;margin-bottom:20px}
  .mono{font-family:'Space Mono',monospace}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{color:#7fa8c4;font-weight:600;text-align:left;padding:10px 8px;border-bottom:1px solid rgba(125,211,252,.14)}
  td{padding:12px 8px;border-bottom:1px solid rgba(125,211,252,.08)}
  .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .grow{flex:1}
  .muted{color:#7fa8c4;font-size:13px}
  form.inline{display:inline}
</style>
</head>
<body>
<header>
  <a class="brand" href="{{ route('inventory.web.households') }}">Inventory</a>
  <nav>
    @auth('inventory')
      <span class="muted">{{ auth('inventory')->user()->name }}</span>
      <form class="inline" method="POST" action="{{ route('inventory.web.logout') }}">
        @csrf
        <button type="submit" class="btn-quiet">Sign out</button>
      </form>
    @else
      <a href="{{ route('inventory.web.login.show') }}">Sign in</a>
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
</body>
</html>
