<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset password — Inventory</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{min-height:100vh;background:#0a141d;font-family:'Plus Jakarta Sans',sans-serif;color:#eaf6ff;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{width:100%;max-width:420px;background:rgba(125,211,252,.07);border:1px solid rgba(125,211,252,.18);border-radius:20px;padding:40px 32px}
  h1{font-size:22px;font-weight:700;color:#7dd3fc;margin-bottom:8px}
  p{font-size:14px;color:#7fa8c4;margin-bottom:28px;line-height:1.5}
  label{display:block;font-size:13px;font-weight:600;color:#b8d8f0;margin-bottom:6px}
  input[type=password]{width:100%;padding:12px 14px;background:rgba(125,211,252,.06);border:1px solid rgba(125,211,252,.22);border-radius:10px;color:#eaf6ff;font-size:15px;font-family:inherit;outline:none;margin-bottom:18px}
  input[type=password]:focus{border-color:#7dd3fc}
  button{width:100%;padding:14px;background:#7dd3fc;color:#06283b;font-size:15px;font-weight:700;font-family:inherit;border:none;border-radius:12px;cursor:pointer;margin-top:4px}
  button:hover{background:#93ddfb}
  .error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);border-radius:10px;padding:12px 14px;font-size:13px;color:#fca5a5;margin-bottom:20px}
</style>
</head>
<body>
<div class="card">
  <h1>Choose a new password</h1>
  <p>Enter a new password for your Inventory account.</p>

  @if ($errors->any())
    <div class="error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('inventory.reset-password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <input type="hidden" name="email" value="{{ $email }}">

    <label for="password">New password</label>
    <input type="password" id="password" name="password" minlength="8" required autofocus>

    <label for="password_confirmation">Confirm password</label>
    <input type="password" id="password_confirmation" name="password_confirmation" minlength="8" required>

    <button type="submit">Reset password</button>
  </form>
</div>
</body>
</html>
